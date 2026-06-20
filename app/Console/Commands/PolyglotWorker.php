<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Polyglot\Avro;
use App\Polyglot\PolyglotActivityFailure;
use App\Polyglot\ServerClient;
use App\Polyglot\WorkflowFiberRunner;
use App\Workflows\Polyglot\PhpSignalQueryWorkflow;
use App\Workflows\Polyglot\PhpSameLanguageWorkflow;
use App\Workflows\Polyglot\PhpToPythonWorkflow;
use App\Workflows\Polyglot\PhpToPythonTypedErrorWorkflow;
use App\Workflows\Polyglot\PhpToPythonTypeRoundtripWorkflow;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;
use Workflow\V2\Support\WorkerProtocolVersion;

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
        'polyglot.php-to-python.type-roundtrip' => PhpToPythonTypeRoundtripWorkflow::class,
        'polyglot.php-to-python.typed-error' => PhpToPythonTypedErrorWorkflow::class,
        'polyglot.php.signal-query' => PhpSignalQueryWorkflow::class,
    ];

    /** @var list<string> */
    private const ACTIVITY_TYPES = [
        'polyglot.php.marker',
        'polyglot.php.describe',
        'polyglot.python-to-php.marker',
        'polyglot.python-to-php.describe',
        'polyglot.python-to-php.echo',
        'polyglot.python-to-php.typed-error',
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

    private function phpSdkVersion(): string
    {
        $version = InstalledVersions::getPrettyVersion('durable-workflow/workflow')
            ?? InstalledVersions::getVersion('durable-workflow/workflow')
            ?? 'unknown';

        return 'durable-workflow/workflow:'.$version;
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
            capabilities: [WorkerProtocolVersion::CAPABILITY_QUERY_TASKS],
            sdkVersion: $this->phpSdkVersion(),
        );

        $this->info(sprintf(
            'polyglot php worker registered: id=%s queue=%s types=[%s]',
            $workerId,
            $taskQueue,
            implode(',', array_keys(self::WORKFLOW_REGISTRY)),
        ));

        $consecutiveIdle = 0;

        while (true) {
            $queryTask = $client->pollQueryTask($workerId, $taskQueue, 1);

            if ($queryTask !== null) {
                $consecutiveIdle = 0;

                try {
                    $this->processQueryTask($client, $workerId, $queryTask);
                } catch (Throwable $exception) {
                    $this->error('polyglot php worker query processing failed: '.$exception->getMessage());

                    $queryTaskId = (string) ($queryTask['query_task_id'] ?? '');
                    $attempt = (int) ($queryTask['query_task_attempt'] ?? 1);

                    if ($queryTaskId !== '') {
                        try {
                            $client->failQueryTask(
                                $queryTaskId,
                                $workerId,
                                $attempt,
                                $exception->getMessage(),
                                failureType: $exception::class,
                            );
                        } catch (Throwable $reportError) {
                            $this->error('failed to report query failure: '.$reportError->getMessage());
                        }
                    }
                }

                $client->heartbeatWorker($workerId);

                continue;
            }

            $client->heartbeatWorker($workerId);

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
            sdkVersion: $this->phpSdkVersion(),
        );

        $this->info(sprintf(
            'polyglot php activity worker registered: id=%s queue=%s types=[%s]',
            $workerId,
            $taskQueue,
            implode(',', self::ACTIVITY_TYPES),
        ));

        $consecutiveIdle = 0;

        while (true) {
            $client->heartbeatWorker($workerId);

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
        $activityReplayValues = $this->extractActivityReplayValues($task);
        $signalReplayValues = $this->extractSignalReplayValues($task);
        $activityReplayIndex = 0;
        $signalReplayIndexes = [];

        $runner = WorkflowFiberRunner::forClass(
            self::WORKFLOW_REGISTRY[$workflowType],
            $workflowId,
            $runId,
            $arguments,
        );

        // Cold replay: drive the fiber from a fresh start, feeding back only
        // history events that match the current suspension. Signals can arrive
        // while an activity is pending, so they are replayed by signal name
        // rather than as generic fiber resume values.
        $step = $runner->step(null);

        while (! $step->completed) {
            if ($step->activity !== null) {
                if (! array_key_exists($activityReplayIndex, $activityReplayValues)) {
                    break;
                }

                $result = $activityReplayValues[$activityReplayIndex];
                $activityReplayIndex++;

                $step = $result instanceof PolyglotActivityFailure
                    ? $runner->throw($result)
                    : $runner->step($result);

                continue;
            }

            if ($step->signalName !== null) {
                $signalName = $step->signalName;
                $signalReplayIndex = $signalReplayIndexes[$signalName] ?? 0;
                $signals = $signalReplayValues[$signalName] ?? [];

                if (! array_key_exists($signalReplayIndex, $signals)) {
                    break;
                }

                $signalReplayIndexes[$signalName] = $signalReplayIndex + 1;
                $step = $runner->step($signals[$signalReplayIndex]);

                continue;
            }

            break;
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
        if ($activity !== null) {
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

            return;
        }

        if ($step->signalName !== null) {
            $command = [
                'type' => 'open_condition_wait',
                'condition_key' => 'polyglot.signal.'.$step->signalName,
                'condition_definition_fingerprint' => hash('sha256', 'polyglot.signal.'.$step->signalName),
            ];

            if ($step->signalTimeoutSeconds !== null) {
                $command['timeout_seconds'] = $step->signalTimeoutSeconds;
            }

            $client->completeWorkflowTask($taskId, $workerId, $attempt, [$command]);

            $this->line(sprintf(
                'polyglot php worker opened signal wait %s for workflow %s',
                $step->signalName,
                $workflowId,
            ));

            return;
        }

        throw new RuntimeException('workflow step suspended without an activity or signal wait.');
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

        if ($activityType === 'polyglot.python-to-php.typed-error') {
            $client->failActivityTask(
                $taskId,
                $workerId,
                $activityAttemptId,
                'php activity planned typed failure',
                'PolyglotPhpTypedError',
                nonRetryable: true,
                details: [
                    'origin' => 'php',
                    'code' => 'PHP_TYPED_ERROR',
                    'structured' => [
                        'language' => 'php',
                        'request' => $arguments[0] ?? null,
                    ],
                ],
            );

            $this->line(sprintf(
                'polyglot php activity worker failed activity %s for workflow %s',
                $activityType,
                (string) ($task['workflow_id'] ?? ''),
            ));

            return;
        }

        $result = match ($activityType) {
            'polyglot.php.marker' => $this->phpRuntimeMarker('polyglot.php.marker', ...$arguments),
            'polyglot.php.describe' => $this->describePhpRuntime('polyglot.php.describe', ...$arguments),
            'polyglot.python-to-php.marker' => $this->phpRuntimeMarker('polyglot.python-to-php.marker', ...$arguments),
            'polyglot.python-to-php.describe' => $this->describePhpRuntime('polyglot.python-to-php.describe', ...$arguments),
            'polyglot.python-to-php.echo' => $this->echoPhpValue(...$arguments),
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
     * @param array<string, mixed> $task
     */
    private function processQueryTask(ServerClient $client, string $workerId, array $task): void
    {
        $queryTaskId = (string) ($task['query_task_id'] ?? '');
        $queryAttempt = (int) ($task['query_task_attempt'] ?? 1);
        $workflowType = (string) ($task['workflow_type'] ?? '');
        $workflowId = (string) ($task['workflow_id'] ?? '');
        $queryName = (string) ($task['query_name'] ?? '');

        if ($queryTaskId === '' || $workflowType === '' || $queryName === '') {
            throw new RuntimeException('query task is missing required identity fields.');
        }

        if ($workflowType !== 'polyglot.php.signal-query' || $queryName !== 'state') {
            throw new RuntimeException(sprintf(
                'no PHP-authored query registered for %s.%s',
                $workflowType,
                $queryName,
            ));
        }

        $signals = $this->extractSignalValues($task, 'polyglot-signal');
        $arguments = $this->decodeArguments($task);
        $request = is_array($arguments[0] ?? null) ? $arguments[0] : [];

        $client->completeQueryTask($queryTaskId, $workerId, $queryAttempt, [
            'workflow_runtime' => 'php',
            'stage' => $signals === [] ? 'waiting' : 'signaled',
            'signal_count' => count($signals),
            'signals' => $signals,
            'request' => $request,
        ]);

        $this->line(sprintf('polyglot php worker answered query %s for workflow %s', $queryName, $workflowId));
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
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function echoPhpValue(array $value): array
    {
        return [
            'runtime' => 'php',
            'value' => $value,
        ];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<int, mixed>
     */
    private function decodeArguments(array $task): array
    {
        $envelope = $task['arguments'] ?? $task['workflow_arguments'] ?? null;
        $taskCodec = (string) ($task['payload_codec'] ?? 'avro');

        if ($this->isMissingPayloadPlaceholder($envelope)) {
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
     * Walk the task's history events and return decoded activity replay values
     * in event order.
     *
     * @param array<string, mixed> $task
     * @return list<mixed>
     */
    private function extractActivityReplayValues(array $task): array
    {
        $events = $this->taskHistoryEvents($task);

        $results = [];
        $exportActivities = $this->historyExportActivities($task);
        $activityIndex = 0;
        $activityTypeIndexes = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = $this->historyEventType($event);
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $activityType = $this->nonEmptyString($payload['activity_type'] ?? null)
                ?? $this->nonEmptyString($payload['activity_class'] ?? null);
            $activityTypeIndex = $activityType === null ? null : ($activityTypeIndexes[$activityType] ?? 0);

            if ($type === 'ActivityCompleted') {
                $envelope = $payload['result'] ?? null;
                $exportActivity = $this->matchingHistoryExportActivity(
                    $payload,
                    $exportActivities,
                    $activityIndex,
                    $activityTypeIndex,
                );

                if ($this->isMissingPayloadPlaceholder($envelope) && $exportActivity !== null) {
                    $envelope = $exportActivity['result'] ?? null;
                }

                if (is_array($envelope) || is_string($envelope) || $envelope === null) {
                    $results[] = Avro::decodeEnvelope($envelope);
                } else {
                    $results[] = $envelope;
                }

                $activityIndex++;
                if ($activityType !== null) {
                    $activityTypeIndexes[$activityType] = $activityTypeIndex + 1;
                }

                continue;
            }

            if ($type === 'ActivityFailed') {
                $results[] = PolyglotActivityFailure::fromHistoryPayload($payload);
                $activityIndex++;
                if ($activityType !== null) {
                    $activityTypeIndexes[$activityType] = $activityTypeIndex + 1;
                }
            }
        }

        return $results;
    }

    /**
     * Walk the task's history events and return decoded signal values keyed by
     * signal name. Each signal queue preserves event order for that name.
     *
     * @param array<string, mixed> $task
     * @return array<string, list<mixed>>
     */
    private function extractSignalReplayValues(array $task): array
    {
        $events = $this->taskHistoryEvents($task);
        $signals = [];
        $exportSignals = $this->historyExportSignals($task);

        foreach ($events as $event) {
            if (! is_array($event) || $this->historyEventType($event) !== 'SignalReceived') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $payloadSignalName = $this->nonEmptyString($payload['signal_name'] ?? null);
            $exportSignal = $this->matchingHistoryExportSignal(
                $payload,
                $exportSignals,
                $payloadSignalName === null ? 0 : count($signals[$payloadSignalName] ?? []),
            );
            $signalName = $payloadSignalName ?? $this->nonEmptyString($exportSignal['name'] ?? null);

            if ($signalName === null) {
                continue;
            }

            $signals[$signalName] ??= [];
            $signals[$signalName][] = $this->signalValue($payload, $exportSignal);
        }

        return $signals;
    }

    /**
     * @param array<string, mixed> $task
     * @return list<mixed>
     */
    private function extractSignalValues(array $task, string $signalName): array
    {
        $signalsByName = $this->extractSignalReplayValues($task);

        return $signalsByName[$signalName] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signalValue(array $payload, ?array $historyExportSignal = null): mixed
    {
        $envelope = $payload['arguments'] ?? $payload['value'] ?? null;

        if ($this->isMissingPayloadPlaceholder($envelope) && $historyExportSignal !== null) {
            $envelope = $historyExportSignal['arguments'] ?? $historyExportSignal['value'] ?? null;
        }

        if ($this->isMissingPayloadPlaceholder($envelope)) {
            return true;
        }

        if (is_array($envelope) || is_string($envelope)) {
            $decoded = Avro::decodeEnvelope($envelope);
        } else {
            $decoded = $envelope;
        }

        if ($decoded === [] || $decoded === null) {
            return true;
        }

        if (is_array($decoded) && array_is_list($decoded)) {
            return count($decoded) === 1 ? $decoded[0] : $decoded;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $task
     * @return array{
     *     by_id: array<string, array<string, mixed>>,
     *     by_wait_id: array<string, array<string, mixed>>,
     *     by_name: array<string, list<array<string, mixed>>>
     * }
     */
    private function historyExportSignals(array $task): array
    {
        $historyExport = is_array($task['history_export'] ?? null) ? $task['history_export'] : [];
        $records = is_array($historyExport['signals'] ?? null) ? $historyExport['signals'] : [];
        $byId = [];
        $byWaitId = [];
        $byName = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $id = $this->nonEmptyString($record['id'] ?? null)
                ?? $this->nonEmptyString($record['signal_id'] ?? null);
            if ($id !== null) {
                $byId[$id] = $record;
            }

            $waitId = $this->nonEmptyString($record['signal_wait_id'] ?? null);
            if ($waitId !== null) {
                $byWaitId[$waitId] = $record;
            }

            $name = $this->nonEmptyString($record['name'] ?? null)
                ?? $this->nonEmptyString($record['signal_name'] ?? null);
            if ($name !== null) {
                $byName[$name] ??= [];
                $byName[$name][] = $record;
            }
        }

        return [
            'by_id' => $byId,
            'by_wait_id' => $byWaitId,
            'by_name' => $byName,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{
     *     by_id: array<string, array<string, mixed>>,
     *     by_wait_id: array<string, array<string, mixed>>,
     *     by_name: array<string, list<array<string, mixed>>>
     * } $exportSignals
     * @return array<string, mixed>|null
     */
    private function matchingHistoryExportSignal(array $payload, array $exportSignals, int $nameIndex): ?array
    {
        $signalId = $this->nonEmptyString($payload['signal_id'] ?? null)
            ?? $this->nonEmptyString($payload['id'] ?? null);
        if ($signalId !== null && isset($exportSignals['by_id'][$signalId])) {
            return $exportSignals['by_id'][$signalId];
        }

        $signalWaitId = $this->nonEmptyString($payload['signal_wait_id'] ?? null);
        if ($signalWaitId !== null && isset($exportSignals['by_wait_id'][$signalWaitId])) {
            return $exportSignals['by_wait_id'][$signalWaitId];
        }

        $signalName = $this->nonEmptyString($payload['signal_name'] ?? null)
            ?? $this->nonEmptyString($payload['name'] ?? null);
        if ($signalName === null) {
            return null;
        }

        $workflowSequence = $this->intValue($payload['workflow_sequence'] ?? null);
        if ($workflowSequence !== null) {
            foreach ($exportSignals['by_name'][$signalName] ?? [] as $record) {
                if ($this->intValue($record['workflow_sequence'] ?? null) === $workflowSequence) {
                    return $record;
                }
            }
        }

        return $exportSignals['by_name'][$signalName][$nameIndex] ?? null;
    }

    /**
     * @param array<string, mixed> $task
     * @return array{
     *     by_id: array<string, array<string, mixed>>,
     *     by_sequence: array<int, array<string, mixed>>,
     *     by_type: array<string, list<array<string, mixed>>>,
     *     ordered: list<array<string, mixed>>
     * }
     */
    private function historyExportActivities(array $task): array
    {
        $historyExport = is_array($task['history_export'] ?? null) ? $task['history_export'] : [];
        $records = is_array($historyExport['activities'] ?? null) ? $historyExport['activities'] : [];
        $byId = [];
        $bySequence = [];
        $byType = [];
        $ordered = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $ordered[] = $record;

            $id = $this->nonEmptyString($record['id'] ?? null)
                ?? $this->nonEmptyString($record['activity_execution_id'] ?? null);
            if ($id !== null) {
                $byId[$id] = $record;
            }

            $sequence = $this->intValue($record['sequence'] ?? null);
            if ($sequence !== null) {
                $bySequence[$sequence] = $record;
            }

            $type = $this->nonEmptyString($record['activity_type'] ?? null)
                ?? $this->nonEmptyString($record['activity_class'] ?? null);
            if ($type !== null) {
                $byType[$type] ??= [];
                $byType[$type][] = $record;
            }
        }

        return [
            'by_id' => $byId,
            'by_sequence' => $bySequence,
            'by_type' => $byType,
            'ordered' => $ordered,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{
     *     by_id: array<string, array<string, mixed>>,
     *     by_sequence: array<int, array<string, mixed>>,
     *     by_type: array<string, list<array<string, mixed>>>,
     *     ordered: list<array<string, mixed>>
     * } $exportActivities
     * @return array<string, mixed>|null
     */
    private function matchingHistoryExportActivity(
        array $payload,
        array $exportActivities,
        int $activityIndex,
        ?int $activityTypeIndex,
    ): ?array {
        $id = $this->nonEmptyString($payload['activity_execution_id'] ?? null)
            ?? $this->nonEmptyString($payload['id'] ?? null);
        if ($id !== null && isset($exportActivities['by_id'][$id])) {
            return $exportActivities['by_id'][$id];
        }

        $sequence = $this->intValue($payload['sequence'] ?? null)
            ?? $this->intValue($payload['workflow_sequence'] ?? null);
        if ($sequence !== null && isset($exportActivities['by_sequence'][$sequence])) {
            return $exportActivities['by_sequence'][$sequence];
        }

        $type = $this->nonEmptyString($payload['activity_type'] ?? null)
            ?? $this->nonEmptyString($payload['activity_class'] ?? null);
        if (
            $type !== null
            && $activityTypeIndex !== null
            && isset($exportActivities['by_type'][$type][$activityTypeIndex])
        ) {
            return $exportActivities['by_type'][$type][$activityTypeIndex];
        }

        return $exportActivities['ordered'][$activityIndex] ?? null;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<int, mixed>
     */
    private function taskHistoryEvents(array $task): array
    {
        $events = $task['history_events'] ?? [];
        if (is_array($events) && $events !== []) {
            return $events;
        }

        $historyExport = is_array($task['history_export'] ?? null) ? $task['history_export'] : [];
        $exportEvents = $historyExport['history_events'] ?? [];

        return is_array($exportEvents) ? array_values($exportEvents) : [];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function historyEventType(array $event): string
    {
        return (string) ($event['event_type'] ?? $event['type'] ?? '');
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function isMissingPayloadPlaceholder(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_array($value) && array_key_exists('blob', $value)) {
            return $value['blob'] === null || $value['blob'] === '';
        }

        return false;
    }
}
