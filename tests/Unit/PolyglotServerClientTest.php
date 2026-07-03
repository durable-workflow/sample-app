<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Polyglot\ServerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class PolyglotServerClientTest extends TestCase
{
    public function test_poll_workflow_task_sends_clamped_protocol_timeout(): void
    {
        $http = new HttpFactory();
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestBody) {
            $requestBody = $request->data();

            return $http->response([
                'task' => [
                    'task_id' => 'task-1',
                    'workflow_id' => 'workflow-1',
                ],
            ]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $task = $client->pollWorkflowTask('php-worker', 'polyglot-php-to-python', 120);

        $this->assertSame('task-1', $task['task_id'] ?? null);
        $this->assertSame([
            'worker_id' => 'php-worker',
            'task_queue' => 'polyglot-php-to-python',
            'timeout_seconds' => 60,
        ], $requestBody);
    }

    public function test_poll_query_task_can_send_immediate_protocol_probe(): void
    {
        $http = new HttpFactory();
        $requestPath = null;
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestPath, &$requestBody) {
            $requestPath = $request->url();
            $requestBody = $request->data();

            return $http->response(['task' => null]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');

        $this->assertNull($client->pollQueryTask('php-worker', 'polyglot-php-to-python', 0));
        $this->assertSame('http://server:8080/api/worker/query-tasks/poll', $requestPath);
        $this->assertSame([
            'worker_id' => 'php-worker',
            'task_queue' => 'polyglot-php-to-python',
            'timeout_seconds' => 0,
        ], $requestBody);
    }

    public function test_poll_request_timeout_tracks_the_requested_poll_window(): void
    {
        $client = new ServerClient(new HttpFactory(), 'http://server:8080', 'test-token', 'default');
        $method = new ReflectionMethod($client, 'requestTimeoutForPoll');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($client, 0));
        $this->assertSame(16, $method->invoke($client, 1));
        $this->assertSame(20, $method->invoke($client, 5));
        $this->assertSame(45, $method->invoke($client, 30));
        $this->assertSame(75, $method->invoke($client, 60));
    }

    public function test_poll_workflow_task_treats_http_timeout_as_empty_poll(): void
    {
        $http = new HttpFactory();
        $http->fake(fn (Request $request) => throw new ConnectionException(
            'cURL error 28: Operation timed out after 35001 milliseconds',
        ));

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');

        $this->assertNull($client->pollWorkflowTask('php-worker', 'polyglot-php-to-python', 30));
    }

    public function test_poll_workflow_task_rethrows_non_timeout_connection_errors(): void
    {
        $http = new HttpFactory();
        $http->fake(fn (Request $request) => throw new ConnectionException(
            'cURL error 7: Failed to connect to server port 8080',
        ));

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');

        $this->expectException(ConnectionException::class);

        $client->pollWorkflowTask('php-worker', 'polyglot-php-to-python', 30);
    }

    public function test_poll_activity_task_uses_activity_endpoint_and_clamped_timeout(): void
    {
        $http = new HttpFactory();
        $requestPath = null;
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestPath, &$requestBody) {
            $requestPath = $request->url();
            $requestBody = $request->data();

            return $http->response([
                'task' => [
                    'task_id' => 'activity-task-1',
                    'activity_attempt_id' => 'attempt-1',
                ],
            ]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $task = $client->pollActivityTask('php-activity-worker', 'polyglot-python-to-php', 120);

        $this->assertSame('http://server:8080/api/worker/activity-tasks/poll', $requestPath);
        $this->assertSame('activity-task-1', $task['task_id'] ?? null);
        $this->assertSame([
            'worker_id' => 'php-activity-worker',
            'task_queue' => 'polyglot-python-to-php',
            'timeout_seconds' => 60,
        ], $requestBody);
    }

    public function test_complete_activity_task_sends_avro_result_envelope(): void
    {
        $http = new HttpFactory();
        $requestPath = null;
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestPath, &$requestBody) {
            $requestPath = $request->url();
            $requestBody = $request->data();

            return $http->response(['recorded' => true]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $client->completeActivityTask(
            'activity-task-1',
            'php-activity-worker',
            'attempt-1',
            ['runtime' => 'php'],
        );

        $this->assertSame('http://server:8080/api/worker/activity-tasks/activity-task-1/complete', $requestPath);
        $this->assertSame('attempt-1', $requestBody['activity_attempt_id'] ?? null);
        $this->assertSame('php-activity-worker', $requestBody['lease_owner'] ?? null);
        $this->assertSame('avro', $requestBody['result']['codec'] ?? null);
        $this->assertIsString($requestBody['result']['blob'] ?? null);
    }

    public function test_fail_activity_task_sends_failure_payload(): void
    {
        $http = new HttpFactory();
        $requestPath = null;
        $requestBody = null;

        $http->fake(function (Request $request) use ($http, &$requestPath, &$requestBody) {
            $requestPath = $request->url();
            $requestBody = $request->data();

            return $http->response(['recorded' => true]);
        });

        $client = new ServerClient($http, 'http://server:8080', 'test-token', 'default');
        $client->failActivityTask(
            'activity-task-1',
            'php-activity-worker',
            'attempt-1',
            'activity exploded',
            RuntimeException::class,
        );

        $this->assertSame('http://server:8080/api/worker/activity-tasks/activity-task-1/fail', $requestPath);
        $this->assertSame([
            'activity_attempt_id' => 'attempt-1',
            'lease_owner' => 'php-activity-worker',
            'failure' => [
                'message' => 'activity exploded',
                'type' => RuntimeException::class,
            ],
        ], $requestBody);
    }
}
