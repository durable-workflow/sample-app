<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ServiceMode;
use App\Workflows\ServiceMode\WelcomeWorkflow;
use DurableWorkflow\Bridge\Laravel\Facades\DurableWorkflow;
use DurableWorkflow\Bridge\Laravel\LaravelWorkflowClient;
use DurableWorkflow\Bridge\Laravel\LaravelWorkflowClientInterface;
use DurableWorkflow\Bridge\ServiceConfiguration;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

final class ServiceModeCommandTest extends TestCase
{
    public function test_command_uses_the_injectable_fake_and_returns_the_matching_waterline_location(): void
    {
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
}
