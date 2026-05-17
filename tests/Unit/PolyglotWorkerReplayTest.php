<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\PolyglotWorker;
use App\Polyglot\Avro;
use App\Polyglot\ServerClient;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class PolyglotWorkerReplayTest extends TestCase
{
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

    public function test_signal_replay_consumes_matching_signal_by_name(): void
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
        $result = Avro::decodeEnvelope($command['result'] ?? null);

        $this->assertSame('complete_workflow', $command['type'] ?? null);
        $this->assertSame($expectedSignal, $result['signal'] ?? null);
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
}
