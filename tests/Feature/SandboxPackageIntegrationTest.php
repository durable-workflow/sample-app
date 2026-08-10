<?php

declare(strict_types=1);

namespace Tests\Feature;

use DurableWorkflow\AI\Exceptions\UnsupportedSandboxCapabilityException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\Providers\LocalSubprocessSandboxProvider;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SandboxPackageIntegrationTest extends TestCase
{
    public function test_sample_uses_package_workflow_and_configured_provider(): void
    {
        $this->assertTrue(class_exists(SandboxAgentWorkflow::class));
        $this->assertSame('local', config('durable-workflow-ai.default'));
        $this->assertInstanceOf(
            LocalSubprocessSandboxProvider::class,
            app(SandboxManager::class)->driver(),
        );
    }

    public function test_sample_does_not_expose_unsafe_sandbox_suspension(): void
    {
        $command = Artisan::all()['app:sandbox'];

        $this->assertFalse($command->getDefinition()->hasOption('suspend-between'));
        $this->assertNotContains(
            'suspendBetweenCalls',
            array_column(config('workflow_mcp.workflows.sandbox.arguments'), 'name'),
        );
    }

    public function test_e2b_suspend_and_resume_fail_before_provider_requests(): void
    {
        config(['durable-workflow-ai.drivers.e2b.api_key' => 'test-api-key']);
        Http::fake();

        $provider = app(SandboxManager::class)->driver('e2b');
        $handle = new SandboxHandle('sandbox-id', 'e2b');

        foreach (['suspend', 'resume'] as $operation) {
            try {
                $provider->{$operation}($handle);
                $this->fail("Expected E2B {$operation} to be unavailable.");
            } catch (UnsupportedSandboxCapabilityException $exception) {
                $this->assertStringContainsString(
                    "does not support [{$operation}]",
                    $exception->getMessage(),
                );
            }
        }

        Http::assertNothingSent();
    }
}
