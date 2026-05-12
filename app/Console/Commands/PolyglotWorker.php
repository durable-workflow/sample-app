<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Polyglot\Avro;
use App\Polyglot\ServerClient;
use App\Polyglot\WorkflowFiberRunner;
use App\Workflows\Polyglot\PhpToPythonWorkflow;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

/**
 * Polling worker that registers PHP-authored workflows on a polyglot
 * task queue and executes them against the standalone Durable Workflow
 * server.
 *
 * On each workflow task the worker performs a cold replay of the
 * workflow Fiber: it instantiates the registered class, starts the
 * fiber, and feeds in the activity results from the task's history
 * until the fiber suspends at the next activity or returns. The
 * resulting `schedule_activity` or `complete_workflow` command is sent
 * back to the server. Activity arguments and results travel through
 * the language-neutral Avro envelope so a Python activity worker on
 * the same task queue can fulfil whatever the workflow schedules.
 *
 * One workflow type is wired in by default — `polyglot.php-to-python.PhpToPythonWorkflow` —
 * which schedules `polyglot.php-to-python.reverse` and `polyglot.php-to-python.tally`
 * activities. Both are implemented in the polyglot Python activity worker.
 */
class PolyglotWorker extends Command
{
    protected $signature = 'app:polyglot-worker
        {--server-url= : Standalone Durable Workflow server URL (defaults to DURABLE_WORKFLOW_SERVER_URL)}
        {--token= : Worker bearer token (defaults to DURABLE_WORKFLOW_AUTH_TOKEN)}
        {--namespace=default : Server namespace}
        {--task-queue= : Task queue to poll (defaults to POLYGLOT_PHP2PY_TASK_QUEUE or polyglot-php-to-python)}
        {--worker-id= : Stable worker id (defaults to a hostname-based id)}
        {--idle-iterations=0 : Stop after this many empty polls (0 = run forever)}
        {--poll-timeout=30 : Long-poll timeout in seconds}';

    protected $description = 'Run the polyglot PHP workflow worker against the standalone Durable Workflow server.';

    /** @var array<string, class-string<\Workflow\V2\Workflow>> */
    private const WORKFLOW_REGISTRY = [
        'polyglot.php-to-python.PhpToPythonWorkflow' => PhpToPythonWorkflow::class,
    ];

    public function handle(HttpFactory $http): int
    {
        $serverUrl = (string) ($this->option('server-url') ?? env('DURABLE_WORKFLOW_SERVER_URL'));
        $token = (string) ($this->option('token') ?? env('DURABLE_WORKFLOW_AUTH_TOKEN', 'test-token'));
        $namespace = (string) $this->option('namespace');
        $taskQueue = (string) ($this->option('task-queue')
            ?? env('POLYGLOT_PHP2PY_TASK_QUEUE', 'polyglot-php-to-python'));
        $workerId = (string) ($this->option('worker-id')
            ?? sprintf('php-worker-%s-%d', gethostname() ?: 'host', getmypid()));
        $idleIterations = max(0, (int) $this->option('idle-iterations'));
        $pollTimeout = max(1, (int) $this->option('poll-timeout'));

        if ($serverUrl === '') {
            $this->error('Set --server-url or DURABLE_WORKFLOW_SERVER_URL before starting the worker.');

            return self::FAILURE;
        }

        $client = new ServerClient($http, rtrim($serverUrl, '/'), $token, $namespace);

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
