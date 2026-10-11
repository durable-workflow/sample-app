<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\WorkflowServer;
use App\Mcp\Tools\DiagnoseWorkflowTool;
use App\Mcp\Tools\RepairWorkflowTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Fixtures\VersionRangeActivity;
use Tests\Fixtures\VersionRangeWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class VersionRangeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_embedded_version_block_is_visible_and_mcp_repair_resumes_without_repeating_activity(): void
    {
        config([
            'queue.default' => 'database',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'waterline.allow_unauthenticated' => true,
            'waterline.engine_source' => 'v2',
        ]);
        // Control delivery order while executing the real published job handlers.
        Queue::fake();
        VersionRangeWorkflow::$version = 1;
        VersionRangeActivity::$executions = 0;
        $value = ['count' => 9007199254740993, 'optional' => null, 'message' => 'recovery λ'];
        $instanceId = 'sample-version-range';
        $workflow = WorkflowStub::make(VersionRangeWorkflow::class, $instanceId);

        try {
            $workflow->start($value);
            $runId = (string) $workflow->runId();
            $this->runNextTask($runId, TaskType::Workflow);
            $this->runNextTask($runId, TaskType::Activity);
            $prefix = $this->history($runId);

            VersionRangeWorkflow::$version = 2;
            $blocked = $this->runNextTask($runId, TaskType::Workflow)->fresh();
            $this->assertSame('waiting', $workflow->refresh()->status());
            $this->assertSame($prefix, $this->history($runId));
            $this->assertSame('version_marker_incompatible_range', $blocked->payload['replay_blocked_reason']);

            $url = route('waterline.instances.runs.show', [$instanceId, $runId]);
            $response = $this->getJson($url)->assertOk()
                ->assertJsonPath('instance_id', $instanceId)
                ->assertJsonPath('run_id', $runId)
                ->assertJsonPath('status', 'waiting')
                ->assertJsonPath('liveness_state', 'workflow_replay_blocked');
            $visibleTask = collect($response->json('tasks'))->firstWhere('id', $blocked->id);
            $this->assertNotNull($visibleTask);
            $this->assertTrue($visibleTask['replay_blocked']);
            $this->assertSame('version_marker_incompatible_range', $visibleTask['replay_blocked_reason']);

            WorkflowServer::tool(DiagnoseWorkflowTool::class, ['workflow_id' => $instanceId, 'run_id' => $runId])
                ->assertOk()
                ->assertStructuredContent(fn (AssertableJson $json) => $json
                    ->where('status', 'waiting')
                    ->where('facts.liveness_state', 'workflow_replay_blocked')
                    ->where('facts.repair_attention', true)
                    ->where('latest_failure', null)
                    ->etc());

            VersionRangeWorkflow::$version = 1;
            WorkflowServer::tool(RepairWorkflowTool::class, ['workflow_id' => $instanceId, 'run_id' => $runId])
                ->assertOk()
                ->assertStructuredContent(fn (AssertableJson $json) => $json
                    ->where('accepted', true)
                    ->where('command.outcome', 'repair_dispatched')
                    ->where('command.resolved_run_id', $runId)
                    ->etc());
            $repaired = $this->runNextTask($runId, TaskType::Workflow)->fresh();

            $this->assertSame($blocked->id, $repaired->id);
            $this->assertSame($blocked->attempt_count + 1, $repaired->attempt_count);
            $this->assertSame($blocked->repair_count + 1, $repaired->repair_count);
            $this->assertSame($runId, $workflow->runId());
            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame($value, $workflow->output());
            $this->assertSame(1, VersionRangeActivity::$executions);
            $history = $this->history($runId);
            $this->assertSame($prefix, array_slice($history, 0, count($prefix)));
            $this->assertSame(['RepairRequested', 'WorkflowCompleted'], array_column(array_slice($history, count($prefix)), 'event_type'));
            $this->getJson($url)->assertOk()->assertJsonPath('run_id', $runId)->assertJsonPath('status', 'completed');
        } finally {
            VersionRangeWorkflow::$version = 1;
            VersionRangeActivity::$executions = 0;
        }
    }

    private function runNextTask(string $runId, TaskType $type): WorkflowTask
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('task_type', $type)->where('status', TaskStatus::Ready)->firstOrFail();
        $job = $type === TaskType::Activity ? new RunActivityTask($task->id) : new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);

        return $task;
    }

    private function history(string $runId): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get()->toArray();
    }
}
