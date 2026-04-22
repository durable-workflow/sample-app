<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\WorkflowServer;
use App\Mcp\Tools\DiagnoseWorkflowTool;
use App\Mcp\Tools\GetWorkflowHistoryTool;
use App\Mcp\Tools\GetWorkflowResultTool;
use App\Mcp\Tools\ListWorkflowsTool;
use App\Mcp\Tools\StartWorkflowTool;
use App\Models\User;
use App\Workflows\Ai\AiWorkflow;
use App\Workflows\Elapsed\ElapsedTimeWorkflow;
use App\Workflows\Microservice\MicroserviceWorkflow;
use App\Workflows\Playwright\CheckConsoleErrorsWorkflow;
use App\Workflows\Prism\PrismWorkflow;
use App\Workflows\Simple\SimpleWorkflow;
use App\Workflows\Webhooks\WebhookWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

class McpWorkflowServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_mcp_tools_have_stable_documented_names(): void
    {
        $this->assertSame('list_workflows', app(ListWorkflowsTool::class)->name());
        $this->assertSame('start_workflow', app(StartWorkflowTool::class)->name());
        $this->assertSame('get_workflow_result', app(GetWorkflowResultTool::class)->name());
        $this->assertSame('get_workflow_history', app(GetWorkflowHistoryTool::class)->name());
        $this->assertSame('diagnose_workflow', app(DiagnoseWorkflowTool::class)->name());
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
                    ->has('available_workflows', 7)
                    ->where('available_workflows.0.key', 'simple')
                    ->where('available_workflows.0.class', SimpleWorkflow::class)
                    ->where('available_workflows.0.pattern', 'deterministic activity chain')
                    ->where('available_workflows.0.command', 'php artisan app:workflow')
                    ->where('available_workflows.0.requires', [])
                    ->where('available_workflows.1.key', 'elapsed')
                    ->where('available_workflows.1.class', ElapsedTimeWorkflow::class)
                    ->where('available_workflows.2.key', 'microservice')
                    ->where('available_workflows.2.class', MicroserviceWorkflow::class)
                    ->where('available_workflows.3.key', 'playwright')
                    ->where('available_workflows.3.class', CheckConsoleErrorsWorkflow::class)
                    ->where('available_workflows.3.arguments.0.name', 'url')
                    ->where('available_workflows.4.key', 'webhook')
                    ->where('available_workflows.4.class', WebhookWorkflow::class)
                    ->where('available_workflows.4.signals.0.name', 'ready')
                    ->where('available_workflows.5.key', 'prism')
                    ->where('available_workflows.5.class', PrismWorkflow::class)
                    ->where('available_workflows.5.requires.0', 'OPENAI_API_KEY')
                    ->where('available_workflows.6.key', 'ai')
                    ->where('available_workflows.6.class', AiWorkflow::class)
                    ->where('available_workflows.6.signals.0.name', 'send')
                    ->where('available_workflows.6.updates.0.name', 'receive')
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

    public function test_workflow_history_payload_previews_do_not_split_multibyte_utf8(): void
    {
        config(['queue.default' => 'database']);

        $instanceId = 'mcp-multibyte-payload-test';

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
                    'large' => str_repeat('a', 4085).'💡'.str_repeat('z', 100),
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
                    ->where('events.1.payload_preview.encoding', 'json')
                    ->where('events.1.payload_preview.truncated', true)
                    ->where('events.1.payload_preview.preview_bytes', fn (int $bytes): bool => $bytes <= 4096)
                    ->where('events.1.payload_preview.preview', function (string $preview): bool {
                        return strlen($preview) <= 4096
                            && preg_match('//u', $preview) === 1
                            && str_ends_with($preview, 'a');
                    })
                    ->missing('events.1.payload')
                    ->etc();
            });
    }

    public function test_agent_can_diagnose_waiting_workflow_with_safe_next_actions(): void
    {
        config(['queue.default' => 'database']);

        $instanceId = 'mcp-diagnose-waiting-test';

        WorkflowServer::tool(StartWorkflowTool::class, [
            'workflow' => 'simple',
            'instance_id' => $instanceId,
            'business_key' => 'mcp-diagnose',
        ])->assertOk();

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', $instanceId)
            ->firstOrFail();

        $run->forceFill([
            'status' => 'waiting',
            'last_progress_at' => now()->subMinutes(4),
        ])->save();

        WorkflowRunSummary::query()
            ->whereKey($run->id)
            ->update([
                'status' => 'waiting',
                'wait_kind' => 'signal',
                'liveness_state' => 'waiting_for_signal',
                'wait_started_at' => now()->subMinutes(4),
                'repair_attention' => false,
                'task_problem' => false,
            ]);

        WorkflowServer::tool(DiagnoseWorkflowTool::class, [
            'workflow_id' => $instanceId,
            'history_limit' => 2,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($instanceId): void {
                $json
                    ->where('found', true)
                    ->where('workflow_id', $instanceId)
                    ->where('diagnosis', 'waiting_for_signal')
                    ->where('facts.status', 'waiting')
                    ->where('facts.wait_kind', 'signal')
                    ->where('facts.liveness_state', 'waiting_for_signal')
                    ->where('facts.business_key', 'mcp-diagnose')
                    ->where('latest_failure', null)
                    ->where('next_actions.0.code', 'inspect_wait_signal')
                    ->has('recent_history', 2)
                    ->etc();
            });
    }

    public function test_agent_diagnosis_surfaces_latest_failure_and_repair_attention(): void
    {
        config(['queue.default' => 'database']);

        $instanceId = 'mcp-diagnose-failed-test';

        WorkflowServer::tool(StartWorkflowTool::class, [
            'workflow' => 'simple',
            'instance_id' => $instanceId,
        ])->assertOk();

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', $instanceId)
            ->firstOrFail();

        $run->forceFill([
            'status' => 'failed',
            'closed_at' => now(),
            'last_progress_at' => now()->subMinute(),
        ])->save();

        WorkflowRunSummary::query()
            ->whereKey($run->id)
            ->update([
                'status' => 'failed',
                'repair_attention' => true,
                'task_problem' => true,
                'repair_blocked_reason' => 'non_retryable_failure',
                'liveness_state' => 'repair_needed',
            ]);

        WorkflowFailure::query()->create([
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity',
            'source_id' => 'activity-1',
            'propagation_kind' => 'leaf',
            'failure_category' => 'application',
            'exception_class' => RuntimeException::class,
            'message' => 'Activity failed during sample diagnosis.',
            'file' => __FILE__,
            'line' => __LINE__,
            'non_retryable' => true,
            'handled' => false,
        ]);

        WorkflowServer::tool(DiagnoseWorkflowTool::class, [
            'workflow_id' => $instanceId,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $json
                    ->where('found', true)
                    ->where('diagnosis', 'failed')
                    ->where('facts.status', 'failed')
                    ->where('facts.repair_attention', true)
                    ->where('facts.task_problem', true)
                    ->where('facts.repair_blocked_reason', 'non_retryable_failure')
                    ->where('latest_failure.source_kind', 'activity')
                    ->where('latest_failure.exception_class', RuntimeException::class)
                    ->where('latest_failure.non_retryable', true)
                    ->where('next_actions.0.code', 'inspect_history')
                    ->where('next_actions.1.code', 'open_waterline')
                    ->where('next_actions.2.code', 'inspect_waterline_diagnostics')
                    ->etc();
            });
    }
}
