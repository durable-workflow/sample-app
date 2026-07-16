<?php

declare(strict_types=1);

namespace Tests\Unit;

use DurableWorkflow\Client;
use DurableWorkflow\Transport\Transport;
use DurableWorkflow\Worker;
use PHPUnit\Framework\TestCase;

final class StandalonePhpWorkerContractTest extends TestCase
{
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
}
