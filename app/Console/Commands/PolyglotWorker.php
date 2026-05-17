<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Polyglot\Avro;
use App\Polyglot\ServerClient;
use App\Polyglot\WorkflowFiberRunner;
use App\Workflows\Polyglot\PhpSameLanguageWorkflow;
use App\Workflows\Polyglot\PhpToPythonWorkflow;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

/**
 * Polling worker that registers PHP-authored workflows or PHP-authored
 * activities on a polyglot task queue and executes them against the
 * standalone Durable Workflow server.
 *
 * On each workflow task the worker performs a cold replay of the
 * workflow Fiber: it instantiates the registered class, starts the
 * fiber, and feeds in the activity results from the task's history
 * until the fiber suspends at the next activity or returns. The
 * resulting `schedule_activity` or `complete_workflow` command is sent
 * back to the server. In activity mode the worker long-polls the
 * activity-task endpoint and returns PHP runtime markers to workflows
 * authored in other languages. Activity arguments and results travel
 * through the language-neutral Avro envelope so each scenario proves
 * the language boundary on the wire.
 *
 * One workflow type is wired in by default —
 * `polyglot.php-to-python.PhpToPythonWorkflow` — which schedules
 * `polyglot.php-to-python.reverse` and `polyglot.php-to-python.tally`
 * activities implemented by the Python activity worker. Activity mode
 * registers `polyglot.python-to-php.*` activities for Python-authored
 * workflows to consume, plus `polyglot.php.*` activities for the PHP
 * same-language sanity scenario.
 */
class PolyglotWorker extends Command
{
    protected $signature = 'app:polyglot-worker
        {--mode=workflow : Worker mode: workflow or activity}
        {--server-url= : Standalone Durable Workflow server URL (defaults to DURABLE_WORKFLOW_SERVER_URL)}
        {--token= : Worker bearer token (defaults to DURABLE_WORKFLOW_AUTH_TOKEN)}
        {--namespace=default : Server namespace}
        {--task-queue= : Task queue to poll (defaults to POLYGLOT_PHP2PY_TASK_QUEUE or POLYGLOT_PY2PHP_TASK_QUEUE by mode)}
        {--worker-id= : Stable worker id (defaults to a hostname-based id)}
        {--idle-iterations=0 : Stop after this many empty polls (0 = run forever)}
        {--poll-timeout=30 : Long-poll timeout in seconds}';

    protected $description = 'Run a polyglot PHP workflow or activity worker against the standalone Durable Workflow server.';

    /** @var array<string, class-string<\Workflow\V2\Workflow>> */
    private const WORKFLOW_REGISTRY = [
        'polyglot.php.greeter' => PhpSameLanguageWorkflow::class,
        'polyglot.php-to-python.PhpToPythonWorkflow' => PhpToPythonWorkflow::class,
    ];

    /** @var list<string> */
    private const ACTIVITY_TYPES = [
        'polyglot.php.marker',
        'polyglot.php.describe',
        'polyglot.python-to-php.marker',
        'polyglot.python-to-php.describe',
    ];

    public function handle(HttpFactory $http): int
    {
        $mode = (string) $this->option('mode');
        $serverUrl = (string) ($this->option('server-url') ?? env('DURABLE_WORKFLOW_SERVER_URL'));
        $token = (string) ($this->option('token') ?? env('DURABLE_WORKFLOW_AUTH_TOKEN', 'test-token'));
        $namespace = (string) $this->option('namespace');
        $taskQueue = (string) ($this->option('task-queue') ?? $this->defaultTaskQueue($mode));
        $workerId = (string) ($this->option('worker-id')
            ?? sprintf('php-%s-worker-%s-%d', $mode, gethostname() ?: 'host', getmypid()));
        $idleIterations = max(0, (int) $this->option('idle-iterations'));
        $pollTimeout = max(1, (int) $this->option('poll-timeout'));

        if (! in_array($mode, ['workflow', 'activity'], true)) {
            $this->error('Unsupported --mode value. Expected workflow or activity.');

            return self::FAILURE;
        }

        if ($serverUrl === '') {
            $this->error('Set --server-url or DURABLE_WORKFLOW_SERVER_URL before starting the worker.');

            return self::FAILURE;
        }

        $client = new ServerClient($http, rtrim($serverUrl, '/'), $token, $namespace);

        if ($mode === 'activity') {
            return $this->runActivityWorker($client, $workerId, $taskQueue, $idleIterations, $pollTimeout);
        }

        return $this->runWorkflowWorker($client, $workerId, $taskQueue, $idleIterations, $pollTimeout);
    }

    private function defaultTaskQueue(string $mode): string
    {
        if ($mode === 'activity') {
            return (string) env('POLYGLOT_PY2PHP_TASK_QUEUE', 'polyglot-python-to-php');
        }

        return (string) env('POLYGLOT_PHP2PY_TASK_QUEUE', 'polyglot-php-to-python');
    }

    private function runWorkflowWorker(
        ServerClient $client,
        string $workerId,
        string $taskQueue,
        int $idleIterations,
        int $pollTimeout,
    ): int {
        $client->registerWorker(
            workerId: $workerId,
            taskQueue: $taskQueue,
            supportedWorkflowTypes: array_keys(self::WORKFLOW_REGISTRY),
        );

        $this->info(sprintf(
            'polyglot php worker registered: id=%s queue=%s types=[%s]',
            $workerId,
            $taskQueue,
            implode(',', array_keys(self::WORKFLOW_REGISTRY)),
        ));

        $consecutiveIdle = 0;

        while (true) {
            $task = $client->pollWorkflowTask($workerId, $taskQueue, $pollTimeout);

            if ($task === null) {
                $consecutiveIdle++;
                if ($idleIterations > 0 && $consecutiveIdle >= $idleIterations) {
                    $this->line(sprintf('polyglot php worker exiting after %d idle polls', $consecutiveIdle));

                    return self::SUCCESS;
                }

                continue;
            }

            $consecutiveIdle = 0;

            try {
                $this->processTask($client, $workerId, $taskQueue, $task);
            } catch (Throwable $exception) {
                $this->error('polyglot php worker task processing failed: '.$exception->getMessage());

                $taskId = (string) ($task['task_id'] ?? '');
                $attempt = (int) ($task['workflow_task_attempt'] ?? 1);

                if ($taskId !== '') {
                    try {
                        $client->failWorkflowTask(
                            $taskId,
                            $workerId,
                            $attempt,
                            $exception->getMessage(),
                            $exception::class,
                        );
                    } catch (Throwable $reportError) {
                        $this->error('failed to report task failure: '.$reportError->getMessage());
                    }
                }
            }
        }
    }

    private function runActivityWorker(
        ServerClient $client,
        string $workerId,
        string $taskQueue,
        int $idleIterations,
        int $pollTimeout,
    ): int {
        $client->registerWorker(
            workerId: $workerId,
            taskQueue: $taskQueue,
            supportedActivityTypes: self::ACTIVITY_TYPES,
        );

        $this->info(sprintf(
            'polyglot php activity worker registered: id=%s queue=%s types=[%s]',
            $workerId,
            $taskQueue,
            implode(',', self::ACTIVITY_TYPES),
        ));

        $consecutiveIdle = 0;

        while (true) {
            $task = $client->pollActivityTask($workerId, $taskQueue, $pollTimeout);

            if ($task === null) {
                $consecutiveIdle++;
                if ($idleIterations > 0 && $consecutiveIdle >= $idleIterations) {
                    $this->line(sprintf('polyglot php activity worker exiting after %d idle polls', $consecutiveIdle));

                    return self::SUCCESS;
                }

                continue;
            }

            $consecutiveIdle = 0;

            try {
                $this->processActivityTask($client, $workerId, $task);
            } catch (Throwable $exception) {
                $this->error('polyglot php activity worker task processing failed: '.$exception->getMessage());

                $taskId = (string) ($task['task_id'] ?? '');
                $activityAttemptId = (string) ($task['activity_attempt_id'] ?? '');

                if ($taskId !== '' && $activityAttemptId !== '') {
                    try {
                        $client->failActivityTask(
                            $taskId,
                            $workerId,
                            $activityAttemptId,
                            $exception->getMessage(),
                            $exception::class,
                        );
                    } catch (Throwable $reportError) {
                        $this->error('failed to report activity failure: '.$reportError->getMessage());
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $task
     */
    private function processTask(ServerClient $client, string $workerId, string $taskQueue, array $task): void
    {
        $workflowType = (string) ($task['workflow_type'] ?? '');
        $workflowId = (string) ($task['workflow_id'] ?? '');
        $runId = (string) ($task['run_id'] ?? '');
        $taskId = (string) ($task['task_id'] ?? '');
        $attempt = (int) ($task['workflow_task_attempt'] ?? 1);

        if ($workflowType === '' || $taskId === '' || $workflowId === '' || $runId === '') {
            throw new RuntimeException('workflow task is missing required identity fields.');
        }

        if (! isset(self::WORKFLOW_REGISTRY[$workflowType])) {
            throw new RuntimeException(sprintf(
                'no PHP-authored workflow registered for type %s',
                $workflowType,
            ));
        }

        $arguments = $this->decodeArguments($task);
        $activityResults = $this->extractActivityResults($task);

        $runner = WorkflowFiberRunner::forClass(
            self::WORKFLOW_REGISTRY[$workflowType],
            $workflowId,
            $runId,
            $arguments,
        );

        // Cold replay: drive the fiber from a fresh start, feeding back
        // every activity result already in this task's history. The next
        // suspension is the command we owe the server.
        $step = $runner->step(null);
        foreach ($activityResults as $result) {
            if ($step->completed) {
                break;
            }
            $step = $runner->step($result);
        }

        if ($step->completed) {
            $client->completeWorkflowTask($taskId, $workerId, $attempt, [[
                'type' => 'complete_workflow',
                'result' => Avro::envelope($step->result),
            ]]);
            $this->line(sprintf('polyglot php worker completed workflow %s', $workflowId));

            return;
        }

        $activity = $step->activity;
        if ($activity === null) {
            throw new RuntimeException('workflow step suspended without an activity call.');
        }

        $client->completeWorkflowTask($taskId, $workerId, $attempt, [[
            'type' => 'schedule_activity',
            'activity_type' => $activity->activity,
            'queue' => $taskQueue,
            'arguments' => Avro::envelope(array_values($activity->arguments)),
        ]]);

        $this->line(sprintf(
            'polyglot php worker scheduled activity %s for workflow %s',
            $activity->activity,
            $workflowId,
        ));
    }

    /**
     * @param array<string, mixed> $task
     */
    private function processActivityTask(ServerClient $client, string $workerId, array $task): void
    {
        $activityType = (string) ($task['activity_type'] ?? '');
        $taskId = (string) ($task['task_id'] ?? '');
        $activityAttemptId = (string) ($task['activity_attempt_id'] ?? '');

        if ($activityType === '' || $taskId === '' || $activityAttemptId === '') {
            throw new RuntimeException('activity task is missing required identity fields.');
        }

        $arguments = $this->decodeArguments($task);
        $result = match ($activityType) {
            'polyglot.php.marker' => $this->phpRuntimeMarker('polyglot.php.marker', ...$arguments),
            'polyglot.php.describe' => $this->describePhpRuntime('polyglot.php.describe', ...$arguments),
            'polyglot.python-to-php.marker' => $this->phpRuntimeMarker('polyglot.python-to-php.marker', ...$arguments),
            'polyglot.python-to-php.describe' => $this->describePhpRuntime('polyglot.python-to-php.describe', ...$arguments),
            default => throw new RuntimeException(sprintf(
                'no PHP-authored activity registered for type %s',
                $activityType,
            )),
        };

        $client->completeActivityTask($taskId, $workerId, $activityAttemptId, $result);

        $this->line(sprintf(
            'polyglot php activity worker completed activity %s for workflow %s',
            $activityType,
            (string) ($task['workflow_id'] ?? ''),
        ));
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function phpRuntimeMarker(string $activityType, array $request): array
    {
        $name = (string) ($request['name'] ?? '');
        $locale = (string) ($request['locale'] ?? 'en');

        return [
            'runtime' => 'php',
            'activity' => $activityType,
            'marker' => 'php-activity-worker',
            'name' => $name,
            'locale' => $locale,
            'message' => sprintf('php handled %s', $name),
        ];
    }

    /**
     * @param array<string, mixed> $marker
     * @return array<string, mixed>
     */
    private function describePhpRuntime(string $activityType, array $marker): array
    {
        $name = (string) ($marker['name'] ?? '');
        $runtime = (string) ($marker['runtime'] ?? 'unknown');

        return [
            'runtime' => 'php',
            'activity' => $activityType,
            'marker_runtime' => $runtime,
            'summary' => sprintf('%s activity marker returned for %s', $runtime, $name),
        ];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<int, mixed>
     */
    private function decodeArguments(array $task): array
    {
        $envelope = $task['arguments'] ?? null;
        $taskCodec = (string) ($task['payload_codec'] ?? 'avro');

        if ($envelope === null) {
            return [];
        }

        if (! is_array($envelope) && ! is_string($envelope)) {
            return [];
        }

        if ($taskCodec !== 'avro' && ! is_array($envelope)) {
            throw new RuntimeException(sprintf(
                'polyglot worker received workflow arguments under unsupported codec %s',
                $taskCodec,
            ));
        }

        $decoded = Avro::decodeEnvelope($envelope);

        return is_array($decoded) ? array_values($decoded) : [$decoded];
    }

    /**
     * Walk the task's history events and return decoded activity results
     * in event order. ActivityFailed terminates replay with an exception.
     *
     * @param array<string, mixed> $task
     * @return list<mixed>
     */
    private function extractActivityResults(array $task): array
    {
        $events = $task['history_events'] ?? [];

        if (! is_array($events)) {
            return [];
        }

        $results = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = (string) ($event['event_type'] ?? '');
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if ($type === 'ActivityCompleted') {
                $envelope = $payload['result'] ?? null;
                if (is_array($envelope) || is_string($envelope) || $envelope === null) {
                    $results[] = Avro::decodeEnvelope($envelope);
                } else {
                    $results[] = $envelope;
                }
                continue;
            }

            if ($type === 'ActivityFailed') {
                $message = (string) ($payload['message'] ?? 'activity failed');

                throw new RuntimeException('polyglot activity failed on the python worker: '.$message);
            }
        }

        return $results;
    }
}
