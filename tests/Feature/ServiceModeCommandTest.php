<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ServiceMode;
use App\Workflows\ServiceMode\PrepareWelcomeActivity;
use App\Workflows\ServiceMode\WelcomeWorkflow;
use DurableWorkflow\Bridge\Laravel\Facades\DurableWorkflow;
use DurableWorkflow\Bridge\Laravel\LaravelWorkflowClient;
use DurableWorkflow\Bridge\Laravel\LaravelWorkflowClientInterface;
use DurableWorkflow\Bridge\ServiceConfiguration;
use DurableWorkflow\Client;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ServiceModeCommandTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalCredentials = [];

    public function test_command_uses_the_injectable_fake_and_returns_the_matching_waterline_location(): void
    {
        $this->activateProcessCredential('client', 'client-only-secret');
        config()->set('service-mode.waterline_url', 'http://localhost:18081/waterline');
        config()->set('durable-workflow.task_queue', 'service-mode-configured-queue');
        $workflowId = 'service-welcome-test';
        $result = [
            'message' => 'Hello, Ada — Python joined the workflow.',
            'php_activity' => ['runtime' => 'php'],
            'python_activity' => ['runtime' => 'python'],
        ];
        $fake = DurableWorkflow::fake()->setWorkflowResult($workflowId, $result);

        $status = Artisan::call('app:service-mode', [
            'name' => 'Ada',
            '--workflow-id' => $workflowId,
            '--json' => true,
        ]);

        $this->assertSame(0, $status);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workflowId, $payload['workflow_id'] ?? null);
        $this->assertSame(WelcomeWorkflow::TYPE, $payload['workflow_type'] ?? null);
        $this->assertSame('service-mode-configured-queue', $payload['task_queue'] ?? null);
        $this->assertSame($result, $payload['result'] ?? null);
        $this->assertSame(
            "http://localhost:18081/waterline/flows/instances/{$workflowId}",
            $payload['waterline_url'] ?? null,
        );
        $this->assertIsInt($payload['result_ms'] ?? null);

        $fake->assertWorkflowStarted(WelcomeWorkflow::class, ['Ada'], $workflowId);
        $fake->assertResultRequested($workflowId);
    }

    public function test_published_service_provider_resolves_the_native_client_from_runtime_url_config(): void
    {
        config()->set('durable-workflow.runtime_url', 'https://runtime.example.test/namespaces/sample');
        config()->set('durable-workflow.namespace', 'sample');
        config()->set('durable-workflow.task_queue', 'service-mode-provider-queue');

        $client = $this->app->make(LaravelWorkflowClientInterface::class);
        $configuration = $this->app->make(ServiceConfiguration::class);

        $this->assertInstanceOf(LaravelWorkflowClient::class, $client);
        $this->assertSame('https://runtime.example.test/namespaces/sample', $configuration->endpoint);
        $this->assertSame('sample', $configuration->namespace);
        $this->assertSame('service-mode-provider-queue', $configuration->taskQueue);
        $this->assertArrayNotHasKey('endpoint', config()->array('durable-workflow'));
    }

    public function test_worker_starts_with_only_its_process_credential_when_the_application_command_uses_the_client(): void
    {
        $this->activateProcessCredential('worker', 'worker-only-secret');
        $requestLog = tempnam(sys_get_temp_dir(), 'service-mode-worker-');
        $this->assertIsString($requestLog);
        [$runtime, $runtimeUrl] = $this->startWorkerRuntime($requestLog);

        try {
            config()->set('durable-workflow.runtime_url', $runtimeUrl);
            config()->set('durable-workflow.namespace', 'sample');
            config()->set('durable-workflow.task_queue', 'service-mode-worker-queue');

            $this->assertFalse($this->app->resolved(Client::class));
            $command = $this->app->make(ServiceMode::class);
            $this->assertInstanceOf(LaravelWorkflowClientInterface::class, $this->commandClient($command));
            $this->assertFalse($this->app->resolved(Client::class));

            $status = Artisan::call('durable-workflow:worker', ['--poll-timeout' => '0']);
            $output = Artisan::output();

            $this->assertSame(0, $status);
            $this->assertStringContainsString(
                'Starting Durable Workflow worker on task queue service-mode-worker-queue.',
                $output,
            );
            $this->assertStringContainsString('credential_role=worker', $output);

            $requests = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($requestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            );
            $this->assertSame(
                ['/api/worker/register', '/api/worker/workflow-tasks/poll'],
                array_column($requests, 'path'),
            );
            $this->assertSame(
                ['Bearer worker-only-secret', 'Bearer worker-only-secret'],
                array_column($requests, 'authorization'),
            );
            $this->assertContains(
                WelcomeWorkflow::TYPE,
                $requests[0]['body']['supported_workflow_types'] ?? [],
            );
            $this->assertContains(
                PrepareWelcomeActivity::TYPE,
                $requests[0]['body']['supported_activity_types'] ?? [],
            );
            $this->assertFalse($this->app->resolved(Client::class));
        } finally {
            $runtime->stop();
            @unlink($requestLog);
        }
    }

    public function test_command_keeps_the_laravel_native_start_contract(): void
    {
        $command = new ReflectionClass(ServiceMode::class);
        $constructor = $command->getConstructor();
        $this->assertNotNull($constructor);
        $client = $constructor->getParameters()[0] ?? null;
        $this->assertNotNull($client);
        $this->assertSame(LaravelWorkflowClientInterface::class, (string) $client->getType());

        $path = $command->getFileName();
        $this->assertIsString($path);
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertStringNotContainsString('->startWorkflow(', $source);
    }

    private function commandClient(ServiceMode $command): object
    {
        $property = (new ReflectionClass($command))->getProperty('workflows');

        return $property->getValue($command);
    }

    private function activateProcessCredential(string $role, string $token): void
    {
        foreach ([
            'DURABLE_WORKFLOW_TOKEN',
            'DURABLE_WORKFLOW_CLIENT_TOKEN',
            'DURABLE_WORKFLOW_WORKER_TOKEN',
            'DURABLE_WORKFLOW_PROCESS_ROLE',
            'DURABLE_WORKFLOW_PROCESS_TOKEN',
        ] as $name) {
            $this->originalCredentials[$name] = getenv($name);
            putenv($name);
        }

        putenv("DURABLE_WORKFLOW_PROCESS_ROLE={$role}");
        putenv("DURABLE_WORKFLOW_PROCESS_TOKEN={$token}");
    }

    protected function tearDown(): void
    {
        foreach ($this->originalCredentials as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    /** @return array{Process, string} */
    private function startWorkerRuntime(string $requestLog): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($socket, "Unable to reserve a worker runtime port: {$errorCode} {$errorMessage}");
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->assertIsString($address);

        $runtime = new Process(
            [PHP_BINARY, '-S', $address, base_path('tests/Fixtures/service-mode-worker-runtime.php')],
            env: ['SERVICE_MODE_WORKER_REQUEST_LOG' => $requestLog],
        );
        $runtime->start();

        $deadline = microtime(true) + 5.0;
        do {
            if (! $runtime->isRunning()) {
                $this->fail('Worker runtime stopped during startup: '.$runtime->getErrorOutput());
            }
            $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return [$runtime, 'http://'.$address];
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        $runtime->stop();
        $this->fail("Worker runtime did not listen on {$address}: {$errorCode} {$errorMessage}");
    }
}
