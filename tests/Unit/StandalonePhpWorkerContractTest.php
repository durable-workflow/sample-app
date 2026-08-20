<?php

declare(strict_types=1);

namespace Tests\Unit;

use DurableWorkflow\Client;
use DurableWorkflow\Codec\AvroBinaryValue;
use DurableWorkflow\Transport\Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\Replayer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandalonePhpWorkerContractTest extends TestCase
{
    #[DataProvider('unsupportedTaskCodecProvider')]
    public function test_manual_workers_reject_non_avro_root_tags_before_payload_decode(
        bool $present,
        mixed $value,
    ): void {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $task = [
            'arguments' => ['codec' => 'avro', 'blob' => 'decode-must-not-run'],
        ];
        if ($present) {
            $task['payload_codec'] = $value;
        }

        try {
            \assertAvroTaskPayloadCodec($task);
            $this->fail('The manual worker accepted a non-Avro root task codec.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('unsupported_payload_codec', $exception->getMessage());
            $this->assertStringContainsString('payload_codec="avro"', $exception->getMessage());
            $this->assertStringNotContainsString('invalid_payload_framing', $exception->getMessage());
        }

        foreach (['activity', 'query'] as $path) {
            [$client, $transport] = $this->recordingClient();
            $manualTask = [
                'task_id' => 'manual-task',
                'query_task_id' => 'manual-task',
                'activity_attempt_id' => 'manual-attempt',
                'lease_owner' => 'manual-worker',
                'activity_type' => 'polyglot.php.marker',
                'workflow_id' => 'manual-workflow',
                'run_id' => 'manual-run',
                'arguments' => ['codec' => 'avro', 'blob' => 'decode-must-not-run'],
                'workflow_arguments' => ['codec' => 'avro', 'blob' => 'decode-must-not-run'],
            ];
            if ($present) {
                $manualTask['payload_codec'] = $value;
            }

            if ($path === 'activity') {
                \handleManualActivityTask($client, 'manual-worker', $manualTask);
            } else {
                \handleManualQueryTask($client, 'manual-worker', $manualTask, $client->payloadCodec());
            }

            $this->assertCount(1, $transport->requests, $path);
            $this->assertStringEndsWith('/fail', $transport->requests[0]['uri'], $path);
            $failure = json_encode($transport->requests[0]['body'], JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('unsupported_payload_codec', $failure, $path);
            $this->assertStringNotContainsString('invalid_payload_framing', $failure, $path);
        }
    }

    /** @return iterable<string, array{bool, mixed}> */
    public static function unsupportedTaskCodecProvider(): iterable
    {
        yield 'missing' => [false, null];
        yield 'empty' => [true, ''];
        yield 'json' => [true, 'json'];
        yield 'unknown' => [true, 'custom'];
        yield 'wrong case' => [true, 'Avro'];
        yield 'null' => [true, null];
        yield 'non-string' => [true, ['avro']];
    }

    public function test_manual_workers_accept_avro_without_inspecting_customer_codec_like_keys(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $client = new Client('http://server:8080');
        $arguments = [[
            'payload_codec' => 'customer-owned',
            'codec' => 'json',
            'metadata' => ['payload_codec' => ['malformed']],
        ]];
        $task = [
            'payload_codec' => 'avro',
            'arguments' => $client->payloadCodec()->envelope($arguments),
        ];

        \assertAvroTaskPayloadCodec($task);

        $this->assertSame($arguments, \decodeArguments($client->payloadCodec(), $task['arguments']));

        foreach (['activity', 'query'] as $path) {
            [$manualClient, $transport] = $this->recordingClient();
            $manualTask = [
                'task_id' => 'manual-task',
                'query_task_id' => 'manual-task',
                'activity_attempt_id' => 'manual-attempt',
                'lease_owner' => 'manual-worker',
                'activity_type' => 'polyglot.php.marker',
                'workflow_id' => 'manual-workflow',
                'run_id' => 'manual-run',
                'payload_codec' => 'avro',
                'arguments' => $manualClient->payloadCodec()->envelope($arguments),
                'workflow_arguments' => $manualClient->payloadCodec()->envelope($arguments),
                'history_events' => [],
            ];

            if ($path === 'activity') {
                \handleManualActivityTask($manualClient, 'manual-worker', $manualTask);
            } else {
                \handleManualQueryTask(
                    $manualClient,
                    'manual-worker',
                    $manualTask,
                    $manualClient->payloadCodec(),
                );
            }

            $this->assertCount(1, $transport->requests, $path);
            $this->assertStringEndsWith('/complete', $transport->requests[0]['uri'], $path);
        }
    }

    public function test_featured_workflow_routes_python_and_rust_activities_and_combines_results(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $workflowQueue = 'polyglot-workflow';
        $pythonQueue = 'polyglot-php-to-python';
        $rustQueue = 'polyglot-to-rust';
        $request = [
            'name' => 'Ada',
            'items' => [
                ['quantity' => 2, 'unit_price_cents' => 1500],
                ['quantity' => 1, 'unit_price_cents' => 4200],
            ],
        ];
        $calculation = [
            'runtime' => 'python',
            'operation' => 'calculate_order_total',
            'item_count' => 2,
            'total_cents' => 7200,
        ];
        $receipt = [
            'runtime' => 'rust',
            'operation' => 'format_receipt',
            'calculation_runtime' => 'python',
            'name' => 'Ada',
            'item_count' => 2,
            'total_cents' => 7200,
            'display_total' => '$72.00',
            'message' => 'Ada: 2 items total $72.00',
        ];
        $codec = (new Client('http://server:8080'))->payloadCodec();
        $replayer = new Replayer($codec);
        $handler = \polyglotWorkflow($workflowQueue, $pythonQueue, $rustQueue);

        $first = $replayer->replay($handler, [], [$request], $workflowQueue)->commands[0];
        $this->assertSame('schedule_activity', $first['type'] ?? null);
        $this->assertSame('polyglot.php-to-python.tally', $first['activity_type'] ?? null);
        $this->assertSame($pythonQueue, $first['queue'] ?? null);
        $this->assertSame([$request['items']], $codec->decodeEnvelope($first['arguments'] ?? null));

        $pythonHistory = [
            [
                'event_type' => 'ActivityScheduled',
                'payload' => ['sequence' => 1, 'activity_type' => 'polyglot.php-to-python.tally'],
            ],
            [
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => 1,
                    'activity_type' => 'polyglot.php-to-python.tally',
                    'result' => $codec->envelope($calculation),
                ],
            ],
        ];
        $second = $replayer->replay($handler, $pythonHistory, [$request], $workflowQueue)->commands[0];
        $this->assertSame('schedule_activity', $second['type'] ?? null);
        $this->assertSame('polyglot.php-to-rust.receipt', $second['activity_type'] ?? null);
        $this->assertSame($rustQueue, $second['queue'] ?? null);
        $this->assertSame([[
            'name' => 'Ada',
            'item_count' => 2,
            'total_cents' => 7200,
            'calculation_runtime' => 'python',
        ]], $codec->decodeEnvelope($second['arguments'] ?? null));

        $completeHistory = [
            ...$pythonHistory,
            [
                'event_type' => 'ActivityScheduled',
                'payload' => ['sequence' => 2, 'activity_type' => 'polyglot.php-to-rust.receipt'],
            ],
            [
                'event_type' => 'ActivityCompleted',
                'payload' => [
                    'sequence' => 2,
                    'activity_type' => 'polyglot.php-to-rust.receipt',
                    'result' => $codec->envelope($receipt),
                ],
            ],
        ];
        $complete = $replayer->replay($handler, $completeHistory, [$request], $workflowQueue)->commands[0];
        $output = $codec->decodeEnvelope($complete['result'] ?? null);

        $this->assertSame('complete_workflow', $complete['type'] ?? null);
        $this->assertSame('PolyglotWorkflow', $output['workflow'] ?? null);
        $this->assertSame('php', $output['workflow_runtime'] ?? null);
        $this->assertSame(['calculation' => 'python', 'receipt' => 'rust'], $output['activity_runtimes'] ?? null);
        $this->assertSame([
            'workflow' => $workflowQueue,
            'python_activity' => $pythonQueue,
            'rust_activity' => $rustQueue,
        ], $output['task_queues'] ?? null);
        $this->assertSame($calculation, $output['python_calculation'] ?? null);
        $this->assertSame($receipt, $output['rust_receipt'] ?? null);
        $this->assertStringContainsString($receipt['message'], (string) ($output['summary'] ?? ''));
    }

    public function test_type_roundtrip_constructs_and_checks_native_binary_values(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $payload = [
            'binary_base64' => 'cG9seWdsb3QtYmluYXJ5AP8B',
            'binary_text' => 'polyglot-binary',
        ];
        $wirePayload = \nativeBinaryPayload($payload);
        $binary = $wirePayload['binary_native'] ?? null;

        $this->assertInstanceOf(AvroBinaryValue::class, $binary);
        $this->assertSame("polyglot-binary\x00\xFF\x01", $binary->bytes);
        $codec = (new Client('http://server:8080'))->payloadCodec();
        $activityInput = $codec->decodeEnvelope($codec->envelope([$wirePayload]))[0];
        $activityEcho = $codec->decodeEnvelope($codec->envelope(\echoNativeBinaryValue($activityInput)));
        $roundtrip = \completeNativeBinaryRoundtrip($payload, $activityEcho);
        $legacyEcho = \echoValue($activityInput);
        $legacyRoundtrip = \completeNativeBinaryRoundtrip($payload, $legacyEcho);

        $this->assertSame([
            'runtime' => 'php',
            'native_type' => 'AvroBinaryValue',
            'base64' => $payload['binary_base64'],
            'byte_length' => 18,
            'matches_expected' => true,
            'text_type' => 'string',
            'text_value' => 'polyglot-binary',
            'text_and_bytes_distinct' => true,
        ], \nativeBinaryEvidence($wirePayload, 'php'));
        $this->assertSame($payload, $roundtrip['echo']['value']);
        $this->assertSame($payload['binary_base64'], $roundtrip['binary_evidence']['activity']['base64']);
        $this->assertSame($payload['binary_base64'], $roundtrip['binary_evidence']['workflow']['base64']);
        $this->assertArrayNotHasKey('binary_evidence', $legacyEcho);
        $this->assertSame(
            $payload['binary_base64'],
            $legacyRoundtrip['binary_evidence']['activity']['base64'],
        );
    }

    public function test_shared_echo_round_trips_fixture_like_keys_as_ordinary_map_fields(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $payloads = [
            ['binary_native' => 'ordinary native field'],
            ['binary_base64' => 'ordinary base64 field'],
            ['binary_text' => 'ordinary text field'],
            [
                'binary_native' => 'ordinary native field',
                'binary_base64' => 'ordinary base64 field',
                'binary_text' => 'ordinary text field',
            ],
            ['binary_native' => AvroBinaryValue::fromBytes("\x00\xFF")],
            [
                'binary_native' => AvroBinaryValue::fromBytes("\x00\xFF"),
                'binary_base64' => ['malformed' => true],
                'binary_text' => 42,
            ],
        ];

        foreach ($payloads as $payload) {
            $echo = \echoValue($payload);

            $this->assertSame($payload, $echo['value']);
            $this->assertArrayNotHasKey('binary_evidence', $echo);
            $this->assertSame('avro', $echo['codec']['codec'] ?? null);
        }
    }

    public function test_native_binary_echo_rejects_a_partial_binary_fixture(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected native PHP AvroBinaryValue');

        \echoNativeBinaryValue(['binary_base64' => 'AA==']);
    }

    public function test_pre_split_php_histories_replay_with_the_current_worker_definitions(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $fixture = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 2).'/polyglot/php_worker/replay_fixtures/pre-binary-activity-split.json',
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $codec = (new Client('http://server:8080'))->payloadCodec();
        $replayer = new Replayer($codec);

        foreach ($fixture['cases'] as $case) {
            $scheduledType = $case['history'][0]['payload']['activity_type'] ?? null;
            $handler = \typeRoundtripWorkflow((string) $scheduledType);
            $result = $replayer->replay(
                $handler,
                $case['history'],
                $case['input'],
                $case['task_queue'],
                [
                    'workflow_id' => 'upgrade-'.$case['direction'],
                    'run_id' => 'pre-binary-split-'.$case['direction'],
                ],
            );

            if ($case['expected_state'] === 'waiting') {
                $this->assertSame([], $result->commands, $case['name']);

                continue;
            }

            $this->assertCount(1, $result->commands, $case['name']);
            $command = $result->commands[0];
            $output = $codec->decodeEnvelope($command['result'] ?? null);
            $this->assertSame('complete_workflow', $command['type'] ?? null, $case['name']);
            $this->assertSame(
                $case['expected_activity_runtime'],
                $output['activity_runtime'] ?? null,
                $case['name'],
            );
            $this->assertSame(
                $case['input'][0]['binary_base64'],
                $output['binary_evidence']['workflow']['base64'] ?? null,
                $case['name'],
            );
        }
    }

    public function test_binary_workflow_names_schedule_the_dedicated_handlers_for_fresh_runs(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $payload = [
            'binary_base64' => 'cG9seWdsb3QtYmluYXJ5AP8B',
            'binary_text' => 'polyglot-binary',
        ];
        $codec = (new Client('http://server:8080'))->payloadCodec();
        $replayer = new Replayer($codec);

        foreach ([
            'polyglot.php-to-python.binary-echo',
            'polyglot.php-to-rust.binary-echo',
        ] as $activityType) {
            $result = $replayer->replay(
                \typeRoundtripWorkflow($activityType),
                [],
                [$payload],
                'fresh-binary',
            );

            $this->assertSame($activityType, $result->commands[0]['activity_type'] ?? null);
        }
    }

    public function test_upgrade_fixtures_cover_every_direction_and_an_unresolved_activity(): void
    {
        $paths = [
            dirname(__DIR__, 2).'/polyglot/php_worker/replay_fixtures/pre-binary-activity-split.json',
            dirname(__DIR__, 2).'/polyglot/python_workflow/replay_fixtures/pre-binary-activity-split.json',
            dirname(__DIR__, 2).'/polyglot/rust_worker/replay_fixtures/pre-binary-activity-split.json',
        ];
        $directions = [];
        $completed = 0;
        $waiting = 0;

        foreach ($paths as $path) {
            $fixture = json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame('durable-workflow.polyglot-upgrade-history.v1', $fixture['schema'] ?? null);
            $this->assertSame('shared-echo', $fixture['source']['activity_generation'] ?? null);

            foreach ($fixture['cases'] ?? [] as $case) {
                $directions[] = $case['direction'];
                $eventTypes = array_column($case['history'], 'event_type');
                $this->assertContains('ActivityScheduled', $eventTypes, $case['name']);
                $this->assertStringEndsWith('.echo', $case['history'][0]['payload']['activity_type']);
                if ($case['expected_state'] === 'completed') {
                    $completed++;
                    $this->assertContains('ActivityCompleted', $eventTypes, $case['name']);
                } else {
                    $waiting++;
                    $this->assertNotContains('ActivityCompleted', $eventTypes, $case['name']);
                }
            }
        }

        sort($directions);
        $this->assertSame([
            'php_to_python',
            'php_to_python',
            'php_to_rust',
            'python_to_php',
            'python_to_rust',
            'rust_to_php',
            'rust_to_python',
        ], $directions);
        $this->assertSame(6, $completed);
        $this->assertSame(1, $waiting);
    }

    public function test_registration_declares_the_signal_consumed_during_replay(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $signalName = 'polyglot-signal';
        $firstSignal = ['source' => 'dw CLI', 'target_runtime' => 'php'];
        $completionSignal = ['source' => 'dw CLI', 'complete' => true];
        $codecClient = new Client('http://server:8080');
        $workflowTask = [
            'task_id' => 'php-signal-contract-task',
            'workflow_task_attempt' => 1,
            'lease_owner' => 'php-signal-contract-worker',
            'workflow_type' => 'polyglot.php.signal-query',
            'payload_codec' => 'avro',
            'workflow_id' => 'php-signal-contract-workflow',
            'run_id' => 'php-signal-contract-run',
            'arguments' => $codecClient->payloadCodec()->envelope([['workflow_runtime' => 'php']]),
            'history_events' => [
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => $signalName,
                        'arguments' => $codecClient->payloadCodec()->envelope([$firstSignal]),
                    ],
                ],
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => $signalName,
                        'arguments' => $codecClient->payloadCodec()->envelope([$completionSignal]),
                    ],
                ],
            ],
        ];
        $transport = new class($workflowTask) implements Transport
        {
            /** @var list<array{method: string, uri: string, body: array<string, mixed>|null}> */
            public array $requests = [];

            /** @param array<string, mixed> $workflowTask */
            public function __construct(private readonly array $workflowTask) {}

            public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
            {
                $this->requests[] = compact('method', 'uri', 'body');

                if (str_ends_with($uri, '/api/worker/register')) {
                    return ['registered' => true];
                }
                if (str_ends_with($uri, '/api/worker/workflow-tasks/poll')) {
                    return ['task' => $this->workflowTask, 'poll_status' => 'leased'];
                }
                if (str_ends_with($uri, '/api/worker/workflow-tasks/php-signal-contract-task/heartbeat')) {
                    return [
                        'task_id' => 'php-signal-contract-task',
                        'lease_owner' => 'php-signal-contract-worker',
                        'workflow_task_attempt' => 1,
                        'renewed' => true,
                    ];
                }
                if (str_ends_with($uri, '/api/worker/activity-tasks/poll')) {
                    return ['poll_status' => 'stopped', 'reason' => 'worker_stopped'];
                }

                return ['acknowledged' => true];
            }
        };
        $client = new Client('http://server:8080', transport: $transport);
        $worker = new Worker(
            $client,
            'polyglot-php-to-python',
            workerId: 'php-signal-contract-worker',
        );

        \configureWorkflows($worker, $client->payloadCodec());
        $worker->run(0);

        $registration = $this->requestEndingWith($transport->requests, '/api/worker/register');
        $signalWorkflow = $registration['body']['workflow_command_contracts']['polyglot.php.signal-query'] ?? [];

        $this->assertContains($signalName, $signalWorkflow['signals'] ?? []);
        $this->assertSame([[
            'name' => $signalName,
            'parameters' => [[
                'name' => 'value',
                'position' => 0,
                'required' => true,
                'variadic' => false,
                'default_available' => false,
                'default' => null,
                'type' => 'array',
                'allows_null' => false,
            ]],
        ]], $signalWorkflow['signal_contracts'] ?? null);

        $completion = $this->requestEndingWith(
            $transport->requests,
            '/api/worker/workflow-tasks/php-signal-contract-task/complete',
        );
        $command = $completion['body']['commands'][0] ?? [];
        $result = $client->payloadCodec()->decodeEnvelope($command['result'] ?? null);

        $this->assertSame('complete_workflow', $command['type'] ?? null);
        $this->assertSame($firstSignal, $result['signal'] ?? null);
    }

    public function test_signal_workflow_suspends_on_the_current_fiber_runtime_while_waiting(): void
    {
        require_once dirname(__DIR__, 2).'/polyglot/php_worker/worker.php';

        $codec = (new Client('http://server:8080'))->payloadCodec();
        $result = (new Replayer($codec))->replay(
            \signalQueryWorkflow(),
            [],
            [['workflow_runtime' => 'php']],
            'polyglot-php-to-python',
        );

        $this->assertSame([[
            'type' => 'open_condition_wait',
            'condition_key' => 'polyglot.signal.polyglot-signal',
            'condition_definition_fingerprint' => hash('sha256', 'polyglot.signal.polyglot-signal'),
        ]], $result->commands);
    }

    /**
     * @param  list<array{method: string, uri: string, body: array<string, mixed>|null}>  $requests
     * @return array{method: string, uri: string, body: array<string, mixed>|null}
     */
    private function requestEndingWith(array $requests, string $suffix): array
    {
        foreach ($requests as $request) {
            if (str_ends_with($request['uri'], $suffix)) {
                return $request;
            }
        }

        $this->fail("No request ended with {$suffix}.");
    }

    /** @return array{Client, object} */
    private function recordingClient(): array
    {
        $transport = new class implements Transport
        {
            /** @var list<array{method: string, uri: string, body: array<string, mixed>|null}> */
            public array $requests = [];

            public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
            {
                $this->requests[] = compact('method', 'uri', 'body');

                return ['acknowledged' => true];
            }
        };

        return [new Client('http://server:8080', transport: $transport), $transport];
    }
}
