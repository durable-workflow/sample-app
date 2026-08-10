<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\WorkflowServer;
use App\Mcp\Tools\StartWorkflowTool;
use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

final class McpSandboxRuntimeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_workflow_rejects_arguments_beyond_the_configured_runtime_boundary(): void
    {
        $this->assertSuspendAttemptIsRejected('sandbox', 'mcp-sandbox-suspend-attempt');
    }

    public function test_fqcn_start_reuses_the_configured_runtime_boundary(): void
    {
        config(['workflow_mcp.allow_fqcn' => true]);

        $this->assertSuspendAttemptIsRejected(
            SandboxAgentWorkflow::class,
            'mcp-sandbox-fqcn-suspend-attempt',
        );
    }

    private function assertSuspendAttemptIsRejected(string $workflow, string $instanceId): void
    {

        WorkflowServer::tool(StartWorkflowTool::class, [
            'workflow' => $workflow,
            'instance_id' => $instanceId,
            'arguments' => [
                [['type' => 'shell', 'args' => ['command' => 'true']]],
                'e2b',
                0,
                true,
            ],
        ])->assertHasErrors(['accepts at most 3 ordered arguments']);

        $this->assertFalse(
            WorkflowRun::query()->where('workflow_instance_id', $instanceId)->exists(),
        );
    }
}
