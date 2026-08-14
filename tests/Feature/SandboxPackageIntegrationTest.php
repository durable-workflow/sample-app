<?php

declare(strict_types=1);

namespace Tests\Feature;

use DurableWorkflow\AI\Activities\DeleteSnapshotActivity;
use DurableWorkflow\AI\Activities\DestroySandboxActivity;
use DurableWorkflow\AI\Activities\DispatchToolCallActivity;
use DurableWorkflow\AI\Activities\InjectSandboxLossActivity;
use DurableWorkflow\AI\Activities\ProvisionSandboxActivity;
use DurableWorkflow\AI\Activities\RestoreSandboxActivity;
use DurableWorkflow\AI\Activities\SnapshotSandboxActivity;
use DurableWorkflow\AI\Exceptions\UnsupportedSandboxCapabilityException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\Providers\LocalSubprocessSandboxProvider;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;

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

    public function test_snapshot_command_reports_created_and_cleaned_workflow_owned_checkpoints(): void
    {
        $this->configureWorkflowStorage();
        WorkflowStub::fake();

        WorkflowStub::mock(ProvisionSandboxActivity::class, [
            'id' => 'sandbox-1',
            'provider' => 'local',
            'metadata' => [],
        ]);
        WorkflowStub::mock(DispatchToolCallActivity::class, [
            'exit_code' => 0,
            'stdout' => 'ok',
            'stderr' => '',
        ]);
        WorkflowStub::mock(SnapshotSandboxActivity::class, function (): string {
            static $snapshot = 0;

            return 'snapshot-'.++$snapshot;
        });
        WorkflowStub::mock(DeleteSnapshotActivity::class, true);
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $status = Artisan::call('app:sandbox', [
            '--snapshot-every' => '2',
            '--wait-seconds' => '5',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $status, $output);
        $this->assertMatchesRegularExpression(
            '/Workflow complete\..*recoveries=0 snapshots=created:2,cleaned:2,retained:none/',
            $output,
        );
        WorkflowStub::assertDispatchedTimes(SnapshotSandboxActivity::class, 2);
        WorkflowStub::assertDispatchedTimes(DeleteSnapshotActivity::class, 2);
    }

    public function test_documented_loss_injection_recovers_the_snapshot_and_continues(): void
    {
        $this->configureWorkflowStorage();
        WorkflowStub::fake();
        $workspaces = ['original' => []];
        $snapshots = [];
        $dispatches = [];
        $injections = [];

        WorkflowStub::mock(ProvisionSandboxActivity::class, [
            'id' => 'original',
            'provider' => 'local',
            'metadata' => [],
        ]);
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$dispatches, &$workspaces): array {
            $sandboxId = $handle['id'];
            $type = $call['type'];
            $args = $call['args'];
            $dispatches[] = "{$sandboxId}:{$type}";

            if ($type === 'write_file') {
                $workspaces[$sandboxId][$args['path']] = $args['contents'];

                return ['exit_code' => 0, 'stdout' => 'wrote '.$args['path'], 'stderr' => ''];
            }

            if ($type === 'read_file') {
                return [
                    'exit_code' => 0,
                    'stdout' => $workspaces[$sandboxId][$args['path']] ?? '',
                    'stderr' => '',
                ];
            }

            return [
                'exit_code' => 0,
                'stdout' => $args['command'] === 'ls -1' ? "README.md\n" : "session-complete\n",
                'stderr' => '',
            ];
        });
        WorkflowStub::mock(SnapshotSandboxActivity::class, function ($context, array $handle) use (&$snapshots, &$workspaces): string {
            $snapshotId = 'snapshot-'.(count($snapshots) + 1);
            $snapshots[$snapshotId] = $workspaces[$handle['id']];

            return $snapshotId;
        });
        WorkflowStub::mock(InjectSandboxLossActivity::class, function ($context, array $handle, string $operationId) use (&$injections, &$workspaces): string {
            $injections[] = $operationId;
            unset($workspaces[$handle['id']]);

            return $operationId;
        });
        WorkflowStub::mock(RestoreSandboxActivity::class, function ($context, string $snapshotId) use (&$snapshots, &$workspaces): array {
            $workspaces['restored'] = $snapshots[$snapshotId];

            return ['id' => 'restored', 'provider' => 'local', 'metadata' => []];
        });
        WorkflowStub::mock(DeleteSnapshotActivity::class, true);
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $status = Artisan::call('app:sandbox', [
            '--snapshot-every' => '2',
            '--inject-loss-after' => '2',
            '--wait-seconds' => '5',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('recoveries=1', $output);
        $this->assertStringContainsString(
            'snapshots=created:2,cleaned:2,retained:none',
            $output,
        );
        $this->assertStringContainsString('# durable sandbox demo', $output);
        $this->assertStringContainsString('session-complete', $output);
        $this->assertSame([
            'original:write_file',
            'original:shell',
            'restored:read_file',
            'restored:shell',
        ], $dispatches);
        $this->assertCount(1, $injections);
        $this->assertStringStartsWith('dwaiv1_', $injections[0]);
        $this->assertSame("# durable sandbox demo\n", $workspaces['restored']['README.md']);
        WorkflowStub::assertDispatchedTimes(DeleteSnapshotActivity::class, 2);
    }

    public function test_loss_injection_rejects_non_local_provider_configuration(): void
    {
        $status = Artisan::call('app:sandbox', [
            '--provider' => 'e2b',
            '--inject-loss-after' => '2',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString(
            'requires the local sandbox provider',
            Artisan::output(),
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

    private function configureWorkflowStorage(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.shared' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
