<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Sandbox;

use App\Sandbox\Exceptions\SandboxGoneException;
use App\Workflows\Sandbox\DestroySandboxActivity;
use App\Workflows\Sandbox\DispatchToolCallActivity;
use App\Workflows\Sandbox\ProvisionSandboxActivity;
use App\Workflows\Sandbox\RestoreSandboxActivity;
use App\Workflows\Sandbox\ResumeSandboxActivity;
use App\Workflows\Sandbox\SandboxAgentWorkflow;
use App\Workflows\Sandbox\SnapshotSandboxActivity;
use App\Workflows\Sandbox\SuspendSandboxActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;

class SandboxAgentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_provisions_dispatches_each_tool_call_then_destroys(): void
    {
        WorkflowStub::fake();

        $destroyCalls = 0;
        $provisionCalls = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, function () use (&$provisionCalls) {
            $provisionCalls++;

            return [
                'id' => 'sbx-123',
                'provider' => 'local',
                'metadata' => ['workspace' => '/tmp/sbx-123'],
            ];
        });

        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, $handle, $call) {
            $this->assertSame('sbx-123', $handle['id']);

            return [
                'exit_code' => 0,
                'stdout' => "ran {$call['type']}",
                'stderr' => '',
            ];
        });

        WorkflowStub::mock(DestroySandboxActivity::class, function ($context, $handle) use (&$destroyCalls) {
            $destroyCalls++;
            $this->assertSame('sbx-123', $handle['id']);

            return true;
        });

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            ['type' => 'shell', 'args' => ['command' => 'ls']],
            ['type' => 'shell', 'args' => ['command' => 'echo hi']],
        ]);

        $output = $workflow->refresh()->output();

        $this->assertSame('sbx-123', $output['sandbox_id']);
        $this->assertCount(2, $output['tool_results']);
        $this->assertSame(0, $output['recovery_count']);
        $this->assertNull($output['latest_snapshot']);
        $this->assertSame(1, $provisionCalls);
        $this->assertSame(1, $destroyCalls, 'destroy must run once on the success path');
    }

    public function test_workflow_snapshots_at_configured_interval(): void
    {
        WorkflowStub::fake();

        $snapshots = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, [
            'id' => 'sbx-snap',
            'provider' => 'local',
            'metadata' => [],
        ]);

        WorkflowStub::mock(DispatchToolCallActivity::class, [
            'exit_code' => 0,
            'stdout' => 'ok',
            'stderr' => '',
        ]);

        WorkflowStub::mock(SnapshotSandboxActivity::class, function () use (&$snapshots) {
            $snapshots++;

            return 'snap-'.$snapshots;
        });

        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(
            [
                ['type' => 'shell', 'args' => ['command' => 'a']],
                ['type' => 'shell', 'args' => ['command' => 'b']],
                ['type' => 'shell', 'args' => ['command' => 'c']],
                ['type' => 'shell', 'args' => ['command' => 'd']],
            ],
            null,
            2, // snapshot every 2 calls
        );

        $output = $workflow->refresh()->output();

        $this->assertSame(2, $snapshots);
        $this->assertSame('snap-2', $output['latest_snapshot']);
    }

    public function test_workflow_recovers_from_sandbox_loss_using_latest_snapshot(): void
    {
        WorkflowStub::fake();

        $provisionCalls = 0;
        $restoreCalls = 0;
        $killedHandles = [];

        WorkflowStub::mock(ProvisionSandboxActivity::class, function () use (&$provisionCalls) {
            $provisionCalls++;

            return [
                'id' => 'sbx-original',
                'provider' => 'local',
                'metadata' => [],
            ];
        });

        WorkflowStub::mock(SnapshotSandboxActivity::class, function ($context, $handle) use (&$killedHandles) {
            // Simulate the original sandbox dying right after the first snapshot —
            // the failure mode the workflow's recovery path is meant to absorb.
            if ($handle['id'] === 'sbx-original' && empty($killedHandles[$handle['id']])) {
                $killedHandles[$handle['id']] = true;
            }

            return 'snap-1';
        });

        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, $handle, $call) use (&$killedHandles) {
            if (! empty($killedHandles[$handle['id']])) {
                throw new SandboxGoneException("{$handle['id']} is gone");
            }

            return [
                'exit_code' => 0,
                'stdout' => $handle['id'].':'.$call['args']['command'],
                'stderr' => '',
            ];
        });

        WorkflowStub::mock(RestoreSandboxActivity::class, function () use (&$restoreCalls) {
            $restoreCalls++;

            return [
                'id' => 'sbx-restored',
                'provider' => 'local',
                'metadata' => [],
            ];
        });

        $destroyed = [];
        WorkflowStub::mock(DestroySandboxActivity::class, function ($context, $handle) use (&$destroyed) {
            $destroyed[] = $handle['id'];

            return true;
        });

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(
            [
                ['type' => 'shell', 'args' => ['command' => 'a']],
                ['type' => 'shell', 'args' => ['command' => 'b']],
                ['type' => 'shell', 'args' => ['command' => 'c']],
            ],
            null,
            1, // snapshot after every call so a snapshot exists when recovery fires
        );

        $output = $workflow->refresh()->output();

        $this->assertSame(1, $output['recovery_count'], 'one recovery should have occurred');
        $this->assertSame('sbx-restored', $output['sandbox_id']);
        $this->assertSame(1, $restoreCalls, 'recovery must restore from the latest snapshot, not provision fresh');
        $this->assertSame(1, $provisionCalls, 'restore replaces the second provision');
        $this->assertCount(3, $output['tool_results']);
        $this->assertContains('sbx-restored', $destroyed);
    }

    public function test_workflow_destroys_sandbox_on_unrecoverable_failure(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(ProvisionSandboxActivity::class, [
            'id' => 'sbx-failure',
            'provider' => 'local',
            'metadata' => [],
        ]);

        WorkflowStub::mock(DispatchToolCallActivity::class, function () {
            // NonRetryableException to keep the test fast: a transient failure
            // would burn through the activity's retry budget before reaching the
            // workflow's failure path. We are exercising "non-sandbox failure
            // still cleans up", not "activity retries work".
            throw new \Workflow\Exceptions\NonRetryableException('non-sandbox failure');
        });

        $destroyed = [];
        WorkflowStub::mock(DestroySandboxActivity::class, function ($context, $handle) use (&$destroyed) {
            $destroyed[] = $handle['id'];

            return true;
        });

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            ['type' => 'shell', 'args' => ['command' => 'a']],
        ]);

        $workflow->refresh();

        $this->assertTrue($workflow->failed(), 'a non-sandbox failure must propagate');
        $this->assertSame(['sbx-failure'], $destroyed, 'destroy must still run on the failure path');
    }

    public function test_workflow_calls_suspend_and_resume_between_tool_calls_when_enabled(): void
    {
        WorkflowStub::fake();

        $suspends = 0;
        $resumes = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, [
            'id' => 'sbx-idle',
            'provider' => 'local',
            'metadata' => [],
        ]);

        WorkflowStub::mock(DispatchToolCallActivity::class, [
            'exit_code' => 0,
            'stdout' => '',
            'stderr' => '',
        ]);

        WorkflowStub::mock(SuspendSandboxActivity::class, function ($context, $handle) use (&$suspends) {
            $suspends++;

            return $handle;
        });

        WorkflowStub::mock(ResumeSandboxActivity::class, function ($context, $handle) use (&$resumes) {
            $resumes++;

            return $handle;
        });

        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(
            [
                ['type' => 'shell', 'args' => ['command' => 'a']],
                ['type' => 'shell', 'args' => ['command' => 'b']],
                ['type' => 'shell', 'args' => ['command' => 'c']],
            ],
            null,
            0,
            true, // suspendBetweenCalls
        );

        $workflow->refresh();

        $this->assertSame(2, $suspends, 'suspend should fire between calls but not after the final one');
        $this->assertSame(2, $resumes, 'resume should pair with suspend');
    }
}
