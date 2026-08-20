<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Codec\AvroPayloadCodec;
use DurableWorkflow\Transport\Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\WorkflowContext;

$sourceAutoload = getenv('DURABLE_WORKFLOW_PHP_SDK_SOURCE_AUTOLOAD');
$autoload = is_string($sourceAutoload) && $sourceAutoload !== ''
    ? $sourceAutoload
    : (file_exists(__DIR__.'/vendor/autoload.php')
        ? __DIR__.'/vendor/autoload.php'
        : dirname(__DIR__, 2).'/vendor/autoload.php');
require $autoload;
require_once __DIR__.'/worker.php';

/** @return list<array{name: string, present: bool, value?: mixed}> */
function unsupportedCodecCases(): array
{
    return [
        ['name' => 'missing', 'present' => false],
        ['name' => 'empty', 'present' => true, 'value' => ''],
        ['name' => 'json', 'present' => true, 'value' => 'json'],
        ['name' => 'unknown', 'present' => true, 'value' => 'custom'],
        ['name' => 'wrong_case', 'present' => true, 'value' => 'Avro'],
        ['name' => 'null', 'present' => true, 'value' => null],
        ['name' => 'non_string', 'present' => true, 'value' => ['avro']],
        ['name' => 'malformed', 'present' => true, 'value' => "avro\0"],
    ];
}

/**
 * @param  array{name: string, present: bool, value?: mixed}  $codecCase
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function sdkProbeTask(string $path, array $codecCase, array $arguments): array
{
    $task = match ($path) {
        'workflow' => [
            'task_id' => 'probe-workflow',
            'workflow_task_attempt' => 1,
            'lease_owner' => 'probe-worker',
            'workflow_id' => 'probe-workflow-id',
            'run_id' => 'probe-workflow-run',
            'workflow_type' => 'probe.workflow',
            'arguments' => $arguments,
            'history_events' => [],
        ],
        'update' => [
            'task_id' => 'probe-update',
            'workflow_task_attempt' => 1,
            'lease_owner' => 'probe-worker',
            'workflow_id' => 'probe-update-id',
            'run_id' => 'probe-update-run',
            'workflow_type' => 'probe.workflow',
            'workflow_update_id' => 'probe-update-command',
            'history_events' => [[
                'event_type' => 'UpdateAccepted',
                'payload' => [
                    'update_id' => 'probe-update-command',
                    'update_name' => 'increment',
                    'arguments' => $arguments,
                ],
            ]],
        ],
        'activity' => [
            'task_id' => 'probe-activity',
            'activity_attempt_id' => 'probe-activity-attempt',
            'lease_owner' => 'probe-worker',
            'activity_type' => 'probe.activity',
            'arguments' => $arguments,
        ],
        'query' => [
            'query_task_id' => 'probe-query',
            'query_task_attempt' => 1,
            'lease_owner' => 'probe-worker',
            'workflow_id' => 'probe-query-id',
            'run_id' => 'probe-query-run',
            'workflow_type' => 'probe.workflow',
            'query_name' => 'status',
            'query_arguments' => $arguments,
            'history_events' => [],
        ],
        default => throw new InvalidArgumentException("Unknown SDK probe path {$path}."),
    };
    if ($codecCase['present']) {
        $task['payload_codec'] = $codecCase['value'] ?? null;
    }

    return $task;
}

/** @return object&Transport */
function probeTransport(string $path, array $task): Transport
{
    return new class($path, $task) implements Transport
    {
        /** @var list<array{method: string, uri: string, body: ?array<string, mixed>}> */
        public array $requests = [];

        private bool $delivered = false;

        /** @param array<string, mixed> $task */
        public function __construct(private readonly string $path, private readonly array $task) {}

        public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
        {
            $this->requests[] = compact('method', 'uri', 'body');
            if (str_ends_with($uri, '/workflow-tasks/poll')) {
                if (! $this->delivered && in_array($this->path, ['workflow', 'update'], true)) {
                    $this->delivered = true;

                    return ['poll_status' => 'leased', 'task' => $this->task];
                }

                return ['poll_status' => 'empty', 'task' => null];
            }
            if (str_ends_with($uri, '/activity-tasks/poll')) {
                if (! $this->delivered && $this->path === 'activity') {
                    $this->delivered = true;

                    return ['poll_status' => 'leased', 'task' => $this->task];
                }

                return ['poll_status' => 'empty', 'task' => null];
            }
            if (str_ends_with($uri, '/query-tasks/poll')) {
                if (! $this->delivered && $this->path === 'query') {
                    $this->delivered = true;

                    return ['poll_status' => 'leased', 'task' => $this->task];
                }

                return ['poll_status' => 'empty', 'task' => null];
            }
            if (str_ends_with($uri, '/heartbeat')) {
                return [
                    'task_id' => $this->task['task_id'],
                    'workflow_task_attempt' => $this->task['workflow_task_attempt'],
                    'lease_owner' => $this->task['lease_owner'],
                    'renewed' => true,
                    'reason' => null,
                ];
            }
            if (str_ends_with($uri, '/complete') || str_ends_with($uri, '/fail')) {
                return ['acknowledged' => true];
            }

            throw new RuntimeException("Unexpected probe request: {$method} {$uri}");
        }
    };
}

/**
 * @param  array<string, mixed>  $task
 * @param  object&Transport  $transport
 */
function sdkProbeWorker(array $task, Transport $transport, int &$handlerCalls): Worker
{
    $worker = new Worker(
        new Client('http://probe.invalid', transport: $transport),
        'probe-queue',
        workerId: 'probe-worker',
    );
    $worker
        ->registerWorkflow(
            'probe.workflow',
            static function (WorkflowContext $context, mixed $input = null) use (&$handlerCalls): string {
                $handlerCalls++;

                return 'workflow-complete';
            },
        )
        ->registerUpdate(
            'probe.workflow',
            'increment',
            static function (QueryContext $context, int $value) use (&$handlerCalls): int {
                $handlerCalls++;

                return $value + 1;
            },
        )
        ->registerActivity(
            'probe.activity',
            static function (ActivityContext $context, mixed $input = null) use (&$handlerCalls): string {
                $handlerCalls++;

                return 'activity-complete';
            },
        )
        ->registerQuery(
            'probe.workflow',
            'status',
            static function (QueryContext $context, mixed $input = null) use (&$handlerCalls): string {
                $handlerCalls++;

                return 'query-complete';
            },
        );

    return $worker;
}

/** @param list<array{method: string, uri: string, body: ?array<string, mixed>}> $requests */
function requestEvidence(array $requests): array
{
    $failures = array_values(array_filter(
        $requests,
        static fn (array $request): bool => str_ends_with($request['uri'], '/fail'),
    ));
    $completions = array_values(array_filter(
        $requests,
        static fn (array $request): bool => str_ends_with($request['uri'], '/complete'),
    ));
    $failureDocument = json_encode($failures[0]['body'] ?? null, JSON_THROW_ON_ERROR);

    return [
        'failure_count' => count($failures),
        'completion_count' => count($completions),
        'unsupported_diagnostic' => str_contains($failureDocument, 'unsupported_payload_codec'),
        'decode_diagnostic' => str_contains($failureDocument, 'invalid_payload_framing'),
    ];
}

/** @return array<string, mixed> */
function runSdkRejectionProbe(string $path, array $codecCase): array
{
    $task = sdkProbeTask(
        $path,
        $codecCase,
        ['codec' => 'avro', 'blob' => 'decode-must-not-run'],
    );
    $transport = probeTransport($path, $task);
    $handlerCalls = 0;
    $handled = sdkProbeWorker($task, $transport, $handlerCalls)->tick(0);
    $requests = requestEvidence($transport->requests);
    $passed = $handled
        && $handlerCalls === 0
        && $requests['failure_count'] === 1
        && $requests['completion_count'] === 0
        && $requests['unsupported_diagnostic']
        && ! $requests['decode_diagnostic'];

    return [
        'runtime' => 'php',
        'worker' => 'sdk',
        'path' => $path,
        'codec_case' => $codecCase['name'],
        'status' => $passed ? 'rejected_before_decode_or_handler' : 'failed',
        'handler_calls' => $handlerCalls,
        ...$requests,
    ];
}

/** @return array<string, mixed> */
function runSdkValidProbe(string $path): array
{
    $arguments = (new AvroPayloadCodec)->envelope([$path === 'update' ? 41 : 'input']);
    $task = sdkProbeTask($path, ['name' => 'avro', 'present' => true, 'value' => 'avro'], $arguments);
    $transport = probeTransport($path, $task);
    $handlerCalls = 0;
    $handled = sdkProbeWorker($task, $transport, $handlerCalls)->tick(0);
    $requests = requestEvidence($transport->requests);
    $passed = $handled
        && $handlerCalls === 1
        && $requests['failure_count'] === 0
        && $requests['completion_count'] === 1;

    return [
        'runtime' => 'php',
        'worker' => 'sdk',
        'path' => $path,
        'codec_case' => 'avro',
        'status' => $passed ? 'decoded_and_handled' : 'failed',
        'handler_calls' => $handlerCalls,
        ...$requests,
    ];
}

/** @return object&Transport */
function manualProbeTransport(): Transport
{
    return new class implements Transport
    {
        /** @var list<array{method: string, uri: string, body: ?array<string, mixed>}> */
        public array $requests = [];

        public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
        {
            $this->requests[] = compact('method', 'uri', 'body');

            return ['acknowledged' => true];
        }
    };
}

/** @return array<string, mixed> */
function runManualProbe(string $path, array $codecCase, bool $valid): array
{
    $transport = manualProbeTransport();
    $client = new Client('http://probe.invalid', transport: $transport);
    $arguments = $valid
        ? $client->payloadCodec()->envelope([['codec' => 'customer-owned', 'payload_codec' => 'metadata']])
        : ['codec' => 'avro', 'blob' => 'decode-must-not-run'];
    $task = [
        'task_id' => 'manual-probe',
        'query_task_id' => 'manual-probe',
        'activity_attempt_id' => 'manual-probe-attempt',
        'lease_owner' => 'manual-probe-worker',
        'activity_type' => 'polyglot.php.marker',
        'workflow_id' => 'manual-probe-workflow',
        'run_id' => 'manual-probe-run',
        'arguments' => $arguments,
        'workflow_arguments' => $arguments,
        'history_events' => [],
    ];
    if ($codecCase['present']) {
        $task['payload_codec'] = $codecCase['value'] ?? null;
    }
    if ($path === 'activity') {
        handleManualActivityTask($client, 'manual-probe-worker', $task);
    } else {
        handleManualQueryTask($client, 'manual-probe-worker', $task, $client->payloadCodec());
    }
    $requests = requestEvidence($transport->requests);
    $passed = $valid
        ? $requests['completion_count'] === 1 && $requests['failure_count'] === 0
        : $requests['completion_count'] === 0
            && $requests['failure_count'] === 1
            && $requests['unsupported_diagnostic']
            && ! $requests['decode_diagnostic'];

    return [
        'runtime' => 'php',
        'worker' => 'manual',
        'path' => $path,
        'codec_case' => $codecCase['name'],
        'status' => $passed
            ? ($valid ? 'decoded_and_handled' : 'rejected_before_decode_or_handler')
            : 'failed',
        ...$requests,
    ];
}

$rejections = [];
$validControls = [];
foreach (unsupportedCodecCases() as $codecCase) {
    foreach (['workflow', 'update', 'activity', 'query'] as $path) {
        $rejections[] = runSdkRejectionProbe($path, $codecCase);
    }
    foreach (['activity', 'query'] as $path) {
        $rejections[] = runManualProbe($path, $codecCase, false);
    }
}
foreach (['workflow', 'update', 'activity', 'query'] as $path) {
    $validControls[] = runSdkValidProbe($path);
}
foreach (['activity', 'query'] as $path) {
    $validControls[] = runManualProbe(
        $path,
        ['name' => 'avro', 'present' => true, 'value' => 'avro'],
        true,
    );
}
$failed = array_values(array_filter(
    [...$rejections, ...$validControls],
    static fn (array $outcome): bool => $outcome['status'] === 'failed',
));
$evidence = [
    'schema' => 'durable-workflow.sample-app.task-codec-rejection-probe',
    'version' => 1,
    'runtime' => 'php',
    'artifact' => [
        'name' => 'durable-workflow/sdk',
        'version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk')
            ?? InstalledVersions::getVersion('durable-workflow/sdk'),
    ],
    'rejection_outcomes' => $rejections,
    'valid_controls' => $validControls,
    'summary' => [
        'status' => $failed === [] ? 'passed' : 'failed',
        'rejection_count' => count($rejections),
        'valid_control_count' => count($validControls),
        'failed_count' => count($failed),
    ],
];
fwrite(STDOUT, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
exit($failed === [] ? 0 : 1);
