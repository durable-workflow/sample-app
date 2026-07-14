<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Codec\PayloadCodec;
use DurableWorkflow\Exception\ActivityFailed;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\PollResponse;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\WorkflowCommand;
use DurableWorkflow\Worker\WorkflowContext;

const WORKFLOW_TYPES = [
    'polyglot.php.greeter',
    'polyglot.php-to-python.PhpToPythonWorkflow',
    'polyglot.php-to-python.type-roundtrip',
    'polyglot.php-to-python.typed-error',
    'polyglot.php-to-rust.greeter',
    'polyglot.php-to-rust.type-roundtrip',
    'polyglot.php.signal-query',
];

const ACTIVITY_TYPES = [
    'polyglot.php.marker',
    'polyglot.php.describe',
    'polyglot.python-to-php.marker',
    'polyglot.python-to-php.describe',
    'polyglot.python-to-php.echo',
    'polyglot.rust-to-php.echo',
    'polyglot.python-to-php.typed-error',
];

/** @return array<string, string|false> */
function commandOptions(): array
{
    $options = getopt('', [
        'mode::',
        'server-url::',
        'token::',
        'namespace::',
        'task-queue::',
        'worker-id::',
        'poll-timeout::',
    ]);

    return is_array($options) ? $options : [];
}

/** @param array<string, string|false> $options */
function option(array $options, string $name, string $default): string
{
    $value = $options[$name] ?? null;

    return is_string($value) && $value !== '' ? $value : $default;
}

/** @return list<mixed> */
function decodeArguments(PayloadCodec $codec, mixed $raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }

    $decoded = (is_array($raw) || is_string($raw)) ? $codec->decodeEnvelope($raw) : $raw;

    return is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
}

function normalizeSignalValue(mixed $value): mixed
{
    if (is_array($value) && array_is_list($value) && count($value) === 1) {
        return $value[0];
    }

    return $value;
}

/** @return list<mixed> */
function signalValues(QueryContext $context, PayloadCodec $codec, string $signalName): array
{
    $history = $context->history;
    if ($history === []) {
        $export = $context->task['history_export'] ?? null;
        $history = is_array($export) && is_array($export['history_events'] ?? null)
            ? array_values($export['history_events'])
            : [];
    }

    $signals = [];
    foreach ($history as $event) {
        if (($event['event_type'] ?? $event['type'] ?? null) !== 'SignalReceived') {
            continue;
        }
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (($payload['signal_name'] ?? null) !== $signalName) {
            continue;
        }
        $raw = $payload['arguments'] ?? $payload['value'] ?? $payload['input'] ?? null;
        $signals[] = normalizeSignalValue(decodeArguments($codec, $raw));
    }

    return $signals;
}

/** @return array<string, mixed> */
function queryWorkflowInput(QueryContext $context, PayloadCodec $codec): array
{
    $raw = $context->task['workflow_arguments'] ?? $context->task['arguments'] ?? null;
    $arguments = decodeArguments($codec, $raw);

    return is_array($arguments[0] ?? null) ? $arguments[0] : [];
}

function decodeFailureDetails(PayloadCodec $codec, mixed $details, string $detailsCodec = ''): mixed
{
    if (is_string($details) && $detailsCodec === 'avro') {
        return $codec->decode($details);
    }
    if (is_array($details) && isset($details['codec'], $details['blob'])) {
        return $codec->decodeEnvelope($details);
    }
    if (is_array($details) && isset($details['payload'])) {
        return decodeFailureDetails($codec, $details['payload'], $detailsCodec);
    }
    if (is_array($details) && array_is_list($details) && count($details) === 1) {
        return decodeFailureDetails($codec, $details[0], $detailsCodec);
    }

    return $details;
}

/** @return array<string, mixed> */
function failureResult(ActivityFailed $failure, PayloadCodec $codec): array
{
    $payload = $failure->failure ?? [];
    $exception = is_array($payload['exception'] ?? null) ? $payload['exception'] : [];
    $details = $exception['details'] ?? $payload['details'] ?? null;
    $detailsCodec = (string) ($exception['details_payload_codec'] ?? $payload['details_payload_codec'] ?? '');

    try {
        $details = decodeFailureDetails($codec, $details, $detailsCodec);
    } catch (Throwable) {
        // Preserve the raw public failure fields if an older server used a
        // details representation that the current codec cannot unwrap.
    }

    return [
        'message' => $failure->getMessage(),
        'activity_type' => $failure->activityType,
        'exception_type' => $failure->failureType
            ?? (is_string($exception['type'] ?? null) ? $exception['type'] : null),
        'non_retryable' => $failure->nonRetryable,
        'details' => $details,
    ];
}

/** @return array<string, mixed> */
function runtimeMarker(string $activityType, array $request): array
{
    $name = (string) ($request['name'] ?? '');

    return [
        'runtime' => 'php',
        'activity' => $activityType,
        'marker' => 'php-activity-worker',
        'name' => $name,
        'locale' => (string) ($request['locale'] ?? 'en'),
        'message' => sprintf('php handled %s', $name),
    ];
}

/** @return array<string, mixed> */
function describeRuntime(string $activityType, array $marker): array
{
    $runtime = (string) ($marker['runtime'] ?? 'unknown');

    return [
        'runtime' => 'php',
        'activity' => $activityType,
        'marker_runtime' => $runtime,
        'summary' => sprintf('%s activity marker returned for %s', $runtime, (string) ($marker['name'] ?? '')),
    ];
}

/** @return array<string, mixed> */
function echoValue(array $value): array
{
    return [
        'runtime' => 'php',
        'value' => $value,
        'codec' => [
            'codec' => 'avro',
            'implementation' => 'Apache Avro',
            'package' => 'apache/avro',
            'version' => InstalledVersions::getPrettyVersion('apache/avro')
                ?? InstalledVersions::getVersion('apache/avro'),
        ],
    ];
}

function configureWorkflows(Worker $worker, PayloadCodec $codec): void
{
    $worker->registerWorkflow(
        'polyglot.php.greeter',
        static function (WorkflowContext $context, array $request): Generator {
            $marker = yield $context->activity('polyglot.php.marker', [$request]);
            $description = yield $context->activity('polyglot.php.describe', [$marker]);

            return [
                'workflow_runtime' => 'php',
                'activity_runtime' => is_array($marker) ? ($marker['runtime'] ?? null) : null,
                'request' => $request,
                'php_marker' => $marker,
                'php_description' => $description,
            ];
        },
    );

    $worker->registerWorkflow(
        'polyglot.php-to-python.PhpToPythonWorkflow',
        static function (WorkflowContext $context, string $value): Generator {
            $reverse = yield $context->activity('polyglot.php-to-python.reverse', [$value]);
            $tally = yield $context->activity('polyglot.php-to-python.tally', [[
                ['quantity' => 2, 'unit_price_cents' => 1500],
                ['quantity' => 1, 'unit_price_cents' => 4200],
            ]]);

            return [
                'workflow_runtime' => 'php',
                'activity_runtime' => is_array($reverse) ? ($reverse['runtime'] ?? null) : null,
                'input' => $value,
                'reverse' => $reverse,
                'tally' => $tally,
            ];
        },
    );

    $worker->registerWorkflow(
        'polyglot.php-to-python.type-roundtrip',
        static function (WorkflowContext $context, array $payload): Generator {
            $echo = yield $context->activity('polyglot.php-to-python.echo', [$payload]);

            return [
                'workflow_runtime' => 'php',
                'activity_runtime' => is_array($echo) ? ($echo['runtime'] ?? null) : null,
                'input' => $payload,
                'echo' => $echo,
            ];
        },
    );

    $worker->registerWorkflow(
        'polyglot.php-to-python.typed-error',
        static function (WorkflowContext $context, array $request) use ($codec): Generator {
            try {
                yield $context->activity('polyglot.php-to-python.typed-error', [$request]);
            } catch (ActivityFailed $failure) {
                return [
                    'workflow_runtime' => 'php',
                    'activity_runtime' => 'python',
                    'failure' => failureResult($failure, $codec),
                    'request' => $request,
                ];
            }

            return [
                'workflow_runtime' => 'php',
                'activity_runtime' => 'python',
                'failure' => null,
                'request' => $request,
            ];
        },
    );

    $worker->registerWorkflow(
        'polyglot.php-to-rust.greeter',
        static function (WorkflowContext $context, array $request): Generator {
            $echo = yield $context->activity('polyglot.php-to-rust.echo', [$request]);

            return [
                'workflow_runtime' => 'php',
                'activity_runtime' => is_array($echo) ? ($echo['runtime'] ?? null) : null,
                'request' => $request,
                'echo' => $echo,
            ];
        },
    );

    $worker->registerWorkflow(
        'polyglot.php-to-rust.type-roundtrip',
        static function (WorkflowContext $context, array $payload): Generator {
            $echo = yield $context->activity('polyglot.php-to-rust.echo', [$payload]);

            return [
                'workflow_runtime' => 'php',
                'activity_runtime' => is_array($echo) ? ($echo['runtime'] ?? null) : null,
                'input' => $payload,
                'echo' => $echo,
            ];
        },
    );

    $worker->registerWorkflow(
        'polyglot.php.signal-query',
        static function (WorkflowContext $context, array $request): Generator {
            $deliveries = $context->signals('polyglot-signal');
            if (count($deliveries) < 2) {
                yield new WorkflowCommand('open_condition_wait', 'condition_wait', [
                    'condition_key' => 'polyglot.signal.polyglot-signal',
                    'condition_definition_fingerprint' => hash('sha256', 'polyglot.signal.polyglot-signal'),
                ]);

                return null;
            }

            return [
                'workflow_runtime' => 'php',
                'request' => $request,
                'signal' => normalizeSignalValue($deliveries[0]),
            ];
        },
    );

    $worker->registerQuery(
        'polyglot.php.signal-query',
        'state',
        static function (QueryContext $context) use ($codec): array {
            $signals = signalValues($context, $codec, 'polyglot-signal');

            return [
                'workflow_runtime' => 'php',
                'stage' => $signals === [] ? 'waiting' : 'signaled',
                'signal_count' => count($signals),
                'signals' => $signals,
                'request' => queryWorkflowInput($context, $codec),
            ];
        },
    );
}

function installShutdownHandlers(bool &$shutdown): void
{
    if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
        return;
    }

    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use (&$shutdown): void { $shutdown = true; });
    pcntl_signal(SIGTERM, static function () use (&$shutdown): void { $shutdown = true; });
}

function runActivityWorker(Client $client, string $workerId, string $taskQueue, int $pollTimeout): void
{
    $client->registerWorker($workerId, $taskQueue, [], ACTIVITY_TYPES, ['graceful_shutdown']);
    fwrite(STDOUT, sprintf(
        "polyglot php activity worker registered: id=%s queue=%s types=[%s]\n",
        $workerId,
        $taskQueue,
        implode(',', ACTIVITY_TYPES),
    ));

    $shutdown = false;
    installShutdownHandlers($shutdown);
    while (!$shutdown) {
        $client->heartbeatWorker($workerId, ['activity_available' => 1]);
        $poll = $client->pollActivityTaskResponse($workerId, $taskQueue, $pollTimeout);
        if (PollResponse::isTerminal($poll)) {
            break;
        }
        $task = $poll['task'] ?? null;
        if (!is_array($task)) {
            continue;
        }

        $taskId = (string) ($task['task_id'] ?? '');
        $attemptId = (string) ($task['activity_attempt_id'] ?? $task['attempt_id'] ?? '');
        $leaseOwner = (string) ($task['lease_owner'] ?? $workerId);
        $activityType = (string) ($task['activity_type'] ?? '');
        try {
            $arguments = decodeArguments($client->payloadCodec(), $task['arguments'] ?? null);
            if ($activityType === 'polyglot.python-to-php.typed-error') {
                $client->failActivityTask(
                    $taskId,
                    $attemptId,
                    $leaseOwner,
                    'php activity planned typed failure',
                    'PolyglotPhpTypedError',
                    true,
                    [
                        'origin' => 'php',
                        'code' => 'PHP_TYPED_ERROR',
                        'structured' => [
                            'language' => 'php',
                            'request' => $arguments[0] ?? null,
                        ],
                    ],
                );
                continue;
            }

            $request = is_array($arguments[0] ?? null) ? $arguments[0] : [];
            $result = match ($activityType) {
                'polyglot.php.marker' => runtimeMarker($activityType, $request),
                'polyglot.php.describe' => describeRuntime($activityType, $request),
                'polyglot.python-to-php.marker' => runtimeMarker($activityType, $request),
                'polyglot.python-to-php.describe' => describeRuntime($activityType, $request),
                'polyglot.python-to-php.echo', 'polyglot.rust-to-php.echo' => echoValue($request),
                default => throw new RuntimeException("No PHP activity is registered for {$activityType}."),
            };
            $client->completeActivityTask($taskId, $attemptId, $leaseOwner, $result);
        } catch (Throwable $exception) {
            $client->failActivityTask(
                $taskId,
                $attemptId,
                $leaseOwner,
                $exception->getMessage(),
                $exception::class,
                true,
            );
        }
    }
}

function runQueryWorker(Client $client, string $workerId, string $taskQueue, int $pollTimeout): void
{
    $client->registerWorker(
        $workerId,
        $taskQueue,
        ['polyglot.php.signal-query'],
        [],
        ['query_tasks', 'graceful_shutdown'],
    );
    fwrite(STDOUT, sprintf(
        "polyglot php query worker registered: id=%s queue=%s types=[polyglot.php.signal-query]\n",
        $workerId,
        $taskQueue,
    ));

    $shutdown = false;
    installShutdownHandlers($shutdown);
    $codec = $client->payloadCodec();
    while (!$shutdown) {
        $client->heartbeatWorker($workerId, ['workflow_available' => 1]);
        $poll = $client->pollQueryTaskResponse($workerId, $taskQueue, $pollTimeout);
        if (PollResponse::isTerminal($poll)) {
            break;
        }
        $task = $poll['task'] ?? null;
        if (!is_array($task)) {
            continue;
        }

        $taskId = (string) ($task['query_task_id'] ?? $task['task_id'] ?? '');
        $attempt = (int) ($task['query_task_attempt'] ?? 1);
        $leaseOwner = (string) ($task['lease_owner'] ?? $workerId);
        try {
            $context = new QueryContext(
                (string) ($task['workflow_id'] ?? ''),
                (string) ($task['run_id'] ?? ''),
                is_array($task['history_events'] ?? null) ? array_values($task['history_events']) : [],
                $task,
            );
            $signals = signalValues($context, $codec, 'polyglot-signal');
            $client->completeQueryTask($taskId, $leaseOwner, $attempt, [
                'workflow_runtime' => 'php',
                'stage' => $signals === [] ? 'waiting' : 'signaled',
                'signal_count' => count($signals),
                'signals' => $signals,
                'request' => queryWorkflowInput($context, $codec),
            ]);
        } catch (Throwable $exception) {
            $client->failQueryTask($taskId, $leaseOwner, $attempt, $exception->getMessage());
        }
    }
}

$options = commandOptions();
$mode = option($options, 'mode', 'workflow');
$serverUrl = option($options, 'server-url', (string) getenv('DURABLE_WORKFLOW_SERVER_URL'));
$token = option($options, 'token', (string) (getenv('DURABLE_WORKFLOW_AUTH_TOKEN') ?: 'test-token'));
$namespace = option($options, 'namespace', (string) (getenv('DURABLE_WORKFLOW_NAMESPACE') ?: 'default'));
$defaultQueue = $mode === 'activity'
    ? (string) (getenv('POLYGLOT_PY2PHP_TASK_QUEUE') ?: 'polyglot-python-to-php')
    : (string) (getenv('POLYGLOT_PHP2PY_TASK_QUEUE') ?: 'polyglot-php-to-python');
$taskQueue = option($options, 'task-queue', $defaultQueue);
$workerId = option(
    $options,
    'worker-id',
    sprintf('php-%s-worker-%s-%d', $mode, gethostname() ?: 'host', getmypid()),
);
$pollTimeout = max(0, (int) option($options, 'poll-timeout', '5'));

if ($serverUrl === '') {
    throw new RuntimeException('Set DURABLE_WORKFLOW_SERVER_URL or pass --server-url.');
}
if (!in_array($mode, ['workflow', 'activity', 'query'], true)) {
    throw new RuntimeException('Expected --mode=workflow, --mode=activity, or --mode=query.');
}

$client = new Client($serverUrl, token: $token, namespace: $namespace);
if ($mode === 'activity') {
    runActivityWorker($client, $workerId, $taskQueue, $pollTimeout);
    exit(0);
}
if ($mode === 'query') {
    runQueryWorker($client, $workerId, $taskQueue, $pollTimeout);
    exit(0);
}

$worker = new Worker($client, $taskQueue, $workerId);
configureWorkflows($worker, $client->payloadCodec());
fwrite(STDOUT, sprintf(
    "polyglot php worker registered: id=%s queue=%s types=[%s]\n",
    $workerId,
    $taskQueue,
    implode(',', WORKFLOW_TYPES),
));
$worker->run($pollTimeout);
