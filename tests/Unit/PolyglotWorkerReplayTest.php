<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\PolyglotWorker;
use App\Polyglot\Avro;
use App\Polyglot\ServerClient;
use App\Workflows\Polyglot\PhpSignalQueryWorkflow;
use Composer\InstalledVersions;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Workflow\QueryMethod;
use Workflow\V2\Support\WorkerProtocolVersion;

final class PolyglotWorkerReplayTest extends TestCase
{
    public function test_php_signal_query_workflow_declares_state_query(): void
    {
        $method = (new ReflectionClass(PhpSignalQueryWorkflow::class))->getMethod('state');
        $attributes = $method->getAttributes(QueryMethod::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('state', $attributes[0]->newInstance()->name);
    }

    public function test_server_client_can_advertise_query_task_capability(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            return $http->response(['ok' => true]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $client->registerWorker(
            workerId: 'php-query-worker',
            taskQueue: 'polyglot-php-to-python',
            supportedWorkflowTypes: ['polyglot.php.signal-query'],
            capabilities: [WorkerProtocolVersion::CAPABILITY_QUERY_TASKS],
        );

        $this->assertCount(1, $requests);
        $this->assertSame(
            'http://server:8080/api/worker/register',
            $requests[0]['url'],
        );
        $this->assertSame(
            [WorkerProtocolVersion::CAPABILITY_QUERY_TASKS],
            $requests[0]['body']['capabilities'] ?? null,
        );
    }

    public function test_server_client_can_heartbeat_registered_worker(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            return $http->response(['acknowledged' => true]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $client->heartbeatWorker('php-query-worker');

        $this->assertCount(1, $requests);
        $this->assertSame(
            'http://server:8080/api/worker/heartbeat',
            $requests[0]['url'],
        );
        $this->assertSame('php-query-worker', $requests[0]['body']['worker_id'] ?? null);
    }

    public function test_workflow_worker_heartbeats_after_advertising_signal_query_type(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            return $http->response(['task' => null, 'acknowledged' => true]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new PolyglotWorker();
        $worker->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $method = new ReflectionMethod($worker, 'runWorkflowWorker');
        $method->setAccessible(true);
        $status = $method->invoke(
            $worker,
            $client,
            'php-workflow-worker',
            'polyglot-php-to-python',
            1,
            1,
        );

        $this->assertSame(0, $status);
        $this->assertSame('http://server:8080/api/worker/register', $requests[0]['url'] ?? null);
        $this->assertContains(
            'polyglot.php.signal-query',
            $requests[0]['body']['supported_workflow_types'] ?? [],
        );
        $this->assertSame(
            [WorkerProtocolVersion::CAPABILITY_QUERY_TASKS],
            $requests[0]['body']['capabilities'] ?? null,
        );
        $this->assertSame('http://server:8080/api/worker/heartbeat', $requests[1]['url'] ?? null);
        $this->assertSame('php-workflow-worker', $requests[1]['body']['worker_id'] ?? null);
        $this->assertSame(
            'http://server:8080/api/worker/query-tasks/poll',
            $requests[2]['url'] ?? null,
        );
    }

    public function test_activity_worker_registration_advertises_installed_workflow_sdk(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            return $http->response(['ok' => true]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new PolyglotWorker();
        $worker->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $method = new ReflectionMethod($worker, 'runActivityWorker');
        $method->setAccessible(true);
        $status = $method->invoke(
            $worker,
            $client,
            'php-activity-worker',
            'polyglot-python-to-php',
            1,
            1,
        );

        $version = InstalledVersions::getPrettyVersion('durable-workflow/workflow')
            ?? InstalledVersions::getVersion('durable-workflow/workflow')
            ?? 'unknown';

        $this->assertSame(0, $status);
        $this->assertCount(3, $requests);
        $this->assertSame(
            'http://server:8080/api/worker/register',
            $requests[0]['url'],
        );
        $this->assertSame(
            'durable-workflow/workflow:'.$version,
            $requests[0]['body']['sdk_version'] ?? null,
        );
        $this->assertContains(
            'polyglot.python-to-php.marker',
            $requests[0]['body']['supported_activity_types'] ?? [],
        );
        $this->assertSame(
            'http://server:8080/api/worker/heartbeat',
            $requests[1]['url'],
        );
        $this->assertSame('php-activity-worker', $requests[1]['body']['worker_id'] ?? null);
    }

    public function test_activity_replay_ignores_signals_until_activity_completion_is_needed(): void
    {
        $body = $this->processWorkflowTask([
            'workflow_type' => 'polyglot.php-to-python.PhpToPythonWorkflow',
            'workflow_id' => 'php-activity-signal-replay',
            'run_id' => 'run-activity-signal-replay',
            'task_id' => 'task-activity-signal-replay',
            'workflow_task_attempt' => 1,
            'arguments' => Avro::envelope(['polyglot']),
            'history_events' => [
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => 'polyglot-signal',
                        'arguments' => Avro::envelope([['message' => 'arrived while activity was pending']]),
                    ],
                ],
                [
                    'event_type' => 'ActivityCompleted',
                    'payload' => [
                        'result' => Avro::envelope([
                            'runtime' => 'python',
                            'input' => 'polyglot',
                            'reversed' => 'tolygolp',
                        ]),
                    ],
                ],
            ],
        ]);

        $command = $body['commands'][0] ?? [];

        $this->assertSame('schedule_activity', $command['type'] ?? null);
        $this->assertSame('polyglot.php-to-python.tally', $command['activity_type'] ?? null);
        $this->assertSame('polyglot-php-to-python', $command['queue'] ?? null);
    }

    public function test_signal_replay_ignores_unmatched_signal_names(): void
    {
        $body = $this->processWorkflowTask([
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_id' => 'php-signal-wrong-name',
            'run_id' => 'run-signal-wrong-name',
            'task_id' => 'task-signal-wrong-name',
            'workflow_task_attempt' => 1,
            'arguments' => Avro::envelope([['case' => 'wrong-name']]),
            'history_events' => [
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => 'other-signal',
                        'arguments' => Avro::envelope([['message' => 'wrong payload']]),
                    ],
                ],
            ],
        ]);

        $command = $body['commands'][0] ?? [];

        $this->assertSame('open_condition_wait', $command['type'] ?? null);
        $this->assertSame('polyglot.signal.polyglot-signal', $command['condition_key'] ?? null);
    }

    public function test_signal_replay_keeps_workflow_open_after_first_matching_signal(): void
    {
        $expectedSignal = ['message' => 'expected payload'];

        $body = $this->processWorkflowTask([
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_id' => 'php-signal-matching-name',
            'run_id' => 'run-signal-matching-name',
            'task_id' => 'task-signal-matching-name',
            'workflow_task_attempt' => 1,
            'arguments' => Avro::envelope([['case' => 'matching-name']]),
            'history_events' => [
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => 'other-signal',
                        'arguments' => Avro::envelope([['message' => 'wrong payload']]),
                    ],
                ],
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => 'polyglot-signal',
                        'arguments' => Avro::envelope([$expectedSignal]),
                    ],
                ],
            ],
        ]);

        $command = $body['commands'][0] ?? [];

        $this->assertSame('open_condition_wait', $command['type'] ?? null);
        $this->assertSame('polyglot.signal.polyglot-signal', $command['condition_key'] ?? null);
    }

    public function test_signal_replay_completes_after_query_observation_signal(): void
    {
        $expectedSignal = ['message' => 'expected payload'];

        $body = $this->processWorkflowTask([
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_id' => 'php-signal-completion',
            'run_id' => 'run-signal-completion',
            'task_id' => 'task-signal-completion',
            'workflow_task_attempt' => 1,
            'arguments' => Avro::envelope([['case' => 'completion-signal']]),
            'history_events' => [
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => 'polyglot-signal',
                        'arguments' => Avro::envelope([$expectedSignal]),
                    ],
                ],
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => 'polyglot-signal',
                        'arguments' => Avro::envelope([['message' => 'query observed']]),
                    ],
                ],
            ],
        ]);

        $command = $body['commands'][0] ?? [];
        $result = Avro::decodeEnvelope($command['result'] ?? null);

        $this->assertSame('complete_workflow', $command['type'] ?? null);
        $this->assertSame($expectedSignal, $result['signal'] ?? null);
    }

    public function test_signal_replay_decodes_matching_history_export_signal_payload(): void
    {
        $expectedSignal = ['message' => 'payload from history export'];

        $body = $this->processWorkflowTask([
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_id' => 'php-signal-history-export',
            'run_id' => 'run-signal-history-export',
            'task_id' => 'task-signal-history-export',
            'workflow_task_attempt' => 1,
            'arguments' => Avro::envelope([['case' => 'history-export-signal']]),
            'history_events' => [
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_id' => 'signal-history-export-1',
                        'signal_name' => 'polyglot-signal',
                    ],
                ],
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_id' => 'signal-history-export-2',
                        'signal_name' => 'polyglot-signal',
                    ],
                ],
            ],
            'history_export' => [
                'signals' => [
                    [
                        'id' => 'signal-history-export-1',
                        'name' => 'polyglot-signal',
                        'payload_codec' => 'avro',
                        'arguments' => Avro::encode([$expectedSignal]),
                    ],
                    [
                        'id' => 'signal-history-export-2',
                        'name' => 'polyglot-signal',
                        'payload_codec' => 'avro',
                        'arguments' => Avro::encode([['message' => 'query observed']]),
                    ],
                ],
            ],
        ]);

        $command = $body['commands'][0] ?? [];
        $result = Avro::decodeEnvelope($command['result'] ?? null);

        $this->assertSame('complete_workflow', $command['type'] ?? null);
        $this->assertSame($expectedSignal, $result['signal'] ?? null);
    }

    public function test_query_task_reports_waiting_state_with_python_parity_shape(): void
    {
        $request = ['workflow_runtime' => 'php'];

        $body = $this->processQueryTask([
            'query_task_id' => 'query-before-signal',
            'query_task_attempt' => 1,
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_id' => 'php-signal-query-before',
            'run_id' => 'run-php-signal-query-before',
            'query_name' => 'state',
            'workflow_arguments' => Avro::envelope([$request]),
            'history_events' => [],
        ]);

        $this->assertSame([
            'workflow_runtime' => 'php',
            'stage' => 'waiting',
            'signal_count' => 0,
            'signals' => [],
            'request' => $request,
        ], $body['result'] ?? null);
    }

    public function test_query_task_reports_signaled_state_with_python_parity_shape(): void
    {
        $request = ['workflow_runtime' => 'php'];
        $signal = ['source' => 'dw CLI', 'target_runtime' => 'php'];

        $body = $this->processQueryTask([
            'query_task_id' => 'query-after-signal',
            'query_task_attempt' => 1,
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_id' => 'php-signal-query-after',
            'run_id' => 'run-php-signal-query-after',
            'query_name' => 'state',
            'workflow_arguments' => Avro::envelope([$request]),
            'history_events' => [
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_name' => 'polyglot-signal',
                        'arguments' => Avro::envelope([$signal]),
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            'workflow_runtime' => 'php',
            'stage' => 'signaled',
            'signal_count' => 1,
            'signals' => [$signal],
            'request' => $request,
        ], $body['result'] ?? null);
    }

    public function test_query_task_reports_history_export_signal_payload_when_event_is_sparse(): void
    {
        $request = ['workflow_runtime' => 'php'];
        $signal = ['source' => 'dw CLI', 'target_runtime' => 'php'];

        $body = $this->processQueryTask([
            'query_task_id' => 'query-after-sparse-signal',
            'query_task_attempt' => 1,
            'workflow_type' => 'polyglot.php.signal-query',
            'workflow_id' => 'php-signal-query-sparse',
            'run_id' => 'run-php-signal-query-sparse',
            'query_name' => 'state',
            'workflow_arguments' => Avro::envelope([$request]),
            'history_events' => [
                [
                    'event_type' => 'SignalReceived',
                    'payload' => [
                        'signal_id' => 'signal-query-sparse-1',
                        'signal_name' => 'polyglot-signal',
                    ],
                ],
            ],
            'history_export' => [
                'signals' => [
                    [
                        'id' => 'signal-query-sparse-1',
                        'name' => 'polyglot-signal',
                        'payload_codec' => 'avro',
                        'arguments' => Avro::encode([$signal]),
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            'workflow_runtime' => 'php',
            'stage' => 'signaled',
            'signal_count' => 1,
            'signals' => [$signal],
            'request' => $request,
        ], $body['result'] ?? null);
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function processWorkflowTask(array $task): array
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            return $http->response(['ok' => true]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new PolyglotWorker();
        $worker->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $method = new ReflectionMethod($worker, 'processTask');
        $method->setAccessible(true);
        $method->invoke($worker, $client, 'php-worker', 'polyglot-php-to-python', $task);

        $this->assertCount(1, $requests);
        $this->assertSame(
            'http://server:8080/api/worker/workflow-tasks/'.$task['task_id'].'/complete',
            $requests[0]['url'],
        );

        return $requests[0]['body'];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function processQueryTask(array $task): array
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            return $http->response(['ok' => true]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $worker = new PolyglotWorker();
        $worker->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        $method = new ReflectionMethod($worker, 'processQueryTask');
        $method->setAccessible(true);
        $method->invoke($worker, $client, 'php-worker', $task);

        $this->assertCount(1, $requests);
        $this->assertSame(
            'http://server:8080/api/worker/query-tasks/'.$task['query_task_id'].'/complete',
            $requests[0]['url'],
        );

        return $requests[0]['body'];
    }
}
