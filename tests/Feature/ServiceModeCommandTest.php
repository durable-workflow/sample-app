<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Workflows\ServiceMode\WelcomeWorkflow;
use DurableWorkflow\Bridge\Laravel\Facades\DurableWorkflow;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ServiceModeCommandTest extends TestCase
{
    public function test_command_uses_the_injectable_fake_and_returns_the_matching_waterline_location(): void
    {
        config()->set('service-mode.waterline_url', 'http://localhost:18081/waterline');
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
        $this->assertSame(WelcomeWorkflow::PHP_TASK_QUEUE, $payload['task_queue'] ?? null);
        $this->assertSame($result, $payload['result'] ?? null);
        $this->assertSame(
            "http://localhost:18081/waterline/flows/instances/{$workflowId}",
            $payload['waterline_url'] ?? null,
        );
        $this->assertIsInt($payload['result_ms'] ?? null);

        $fake->assertWorkflowStarted(WelcomeWorkflow::TYPE, ['Ada']);
        $fake->assertResultRequested($workflowId);
    }
}
