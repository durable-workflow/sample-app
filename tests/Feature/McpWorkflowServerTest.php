<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\WorkflowServer;
use App\Mcp\Tools\GetWorkflowHistoryTool;
use App\Mcp\Tools\GetWorkflowResultTool;
use App\Mcp\Tools\ListWorkflowsTool;
use App\Mcp\Tools\StartWorkflowTool;
use App\Models\User;
use App\Workflows\Prism\PrismWorkflow;
use App\Workflows\Simple\SimpleWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

class McpWorkflowServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_mcp_tools_have_stable_documented_names(): void
    {
        $this->assertSame('list_workflows', app(ListWorkflowsTool::class)->name());
        $this->assertSame('start_workflow', app(StartWorkflowTool::class)->name());
        $this->assertSame('get_workflow_result', app(GetWorkflowResultTool::class)->name());
        $this->assertSame('get_workflow_history', app(GetWorkflowHistoryTool::class)->name());
    }

    public function test_list_workflows_returns_v2_agent_contract_metadata(): void
    {
        WorkflowServer::tool(ListWorkflowsTool::class)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $json
                    ->where('allow_fqcn', false)
                    ->where('workflow_id_kind', 'workflow_instance_id')
                    ->where('run_id_kind', 'workflow_run_id')
                    ->has('status_values')
                    ->has('available_workflows', 3)
                    ->where('available_workflows.0.key', 'simple')
                    ->where('available_workflows.0.class', SimpleWorkflow::class)
                    ->where('available_workflows.0.requires', [])
                    ->where('available_workflows.2.key', 'prism')
                    ->where('available_workflows.2.class', PrismWorkflow::class)
                    ->where('available_workflows.2.requires.0', 'OPENAI_API_KEY')
                    ->etc();
            });
    }

    public function test_start_workflow_rejects_non_v2_workflow_mappings_before_starting(): void
    {
        config(['workflow_mcp.workflows.invalid' => User::class]);

        WorkflowServer::tool(StartWorkflowTool::class, ['workflow' => 'invalid'])
            ->assertHasErrors(['not a valid Workflow']);
    }

    public function test_agent_can_start_poll_and_inspect_simple_workflow(): void
    {
        config(['queue.default' => 'database']);

        $instanceId = 'mcp-simple-test';

        WorkflowServer::tool(StartWorkflowTool::class, [
            'workflow' => 'simple',
            'instance_id' => $instanceId,
            'business_key' => 'mcp-demo',
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($instanceId): void {
                $json
                    ->where('workflow_id', $instanceId)
                    ->where('workflow', 'simple')
                    ->where('workflow_class', SimpleWorkflow::class)
                    ->where('status', 'pending')
                    ->where('running', true)
                    ->where('business_key', 'mcp-demo')
                    ->where('command.status', 'accepted')
                    ->has('run_id')
                    ->etc();
            });

        WorkflowServer::tool(GetWorkflowResultTool::class, [
            'workflow_id' => $instanceId,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($instanceId): void {
                $json
                    ->where('found', true)
                    ->where('workflow_id', $instanceId)
                    ->where('status', 'pending')
                    ->where('running', true)
                    ->where('output', null)
                    ->where('business_key', 'mcp-demo')
                    ->etc();
            });

        WorkflowServer::tool(GetWorkflowHistoryTool::class, [
            'workflow_id' => $instanceId,
            'limit' => 10,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($instanceId): void {
                $json
                    ->where('found', true)
                    ->where('workflow_id', $instanceId)
                    ->where('status', 'pending')
                    ->where('payloads_included', false)
                    ->where('events_are_most_recent', true)
                    ->where('events.0.event_type', 'StartAccepted')
                    ->where('events.1.event_type', 'WorkflowStarted')
                    ->has('events', 2)
                    ->etc();
            });
    }

    public function test_workflow_history_payloads_are_returned_as_bounded_previews(): void
    {
        config(['queue.default' => 'database']);

        $instanceId = 'mcp-large-payload-test';

        WorkflowServer::tool(StartWorkflowTool::class, [
            'workflow' => 'simple',
            'instance_id' => $instanceId,
        ])->assertOk();

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', $instanceId)
            ->firstOrFail();

        $run->historyEvents()
            ->where('sequence', 2)
            ->firstOrFail()
            ->forceFill([
                'payload' => [
                    'large' => str_repeat('x', 12000),
                ],
            ])
            ->save();

        WorkflowServer::tool(GetWorkflowHistoryTool::class, [
            'workflow_id' => $instanceId,
            'include_payloads' => true,
            'limit' => 10,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $json
                    ->where('found', true)
                    ->where('payloads_included', true)
                    ->where('payload_preview_limit_bytes', 4096)
                    ->where('events.1.payload_keys.0', 'large')
                    ->where('events.1.payload_size_bytes', fn (int $bytes): bool => $bytes > 4096)
                    ->where('events.1.payload_preview.encoding', 'json')
                    ->where('events.1.payload_preview.truncated', true)
                    ->where('events.1.payload_preview.preview_bytes', 4096)
                    ->where('events.1.payload_preview.preview', fn (string $preview): bool => strlen($preview) === 4096)
                    ->missing('events.1.payload')
                    ->etc();
            });
    }
}
