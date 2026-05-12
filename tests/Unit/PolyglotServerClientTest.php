<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Polyglot\ServerClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

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
}
