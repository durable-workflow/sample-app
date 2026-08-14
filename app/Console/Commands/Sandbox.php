<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DurableWorkflow\AI\Activities\DeleteSnapshotActivity;
use DurableWorkflow\AI\Activities\SnapshotSandboxActivity;
use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\WorkflowStub;

class Sandbox extends Command
{
    protected $signature = 'app:sandbox
        {--provider= : Sandbox provider override (defaults to config(\'durable-workflow-ai.default\'))}
        {--snapshot-every=2 : Snapshot the workspace after every N tool calls (0 disables snapshots)}
        {--inject-loss-after= : Inject local sandbox loss after N completed tool calls without journaling a tool effect}
        {--wait-seconds=180 : Seconds to wait for the workflow to reach a terminal state}';

    protected $description = 'Run the durable sandbox orchestration sample against the configured provider';

    public function handle(): int
    {
        $toolCalls = $this->demoToolCalls();
        $provider = $this->stringOption('provider');
        $snapshotEvery = (int) $this->option('snapshot-every');
        $injectLossAfter = $this->positiveIntOption('inject-loss-after');
        $waitSeconds = $this->positiveIntOption('wait-seconds') ?? 180;
        $selectedProvider = $provider ?? (string) config('durable-workflow-ai.default');

        if ($injectLossAfter !== null && $selectedProvider !== 'local') {
            $this->error('Loss injection is development/test-only and requires the local sandbox provider.');

            return self::FAILURE;
        }

        if ($injectLossAfter !== null
            && ! (bool) config('durable-workflow-ai.demo.allow_local_loss_injection', false)) {
            $this->error('Local sandbox loss injection is disabled by application configuration.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Starting sandbox agent workflow against [%s] provider with %d tool call%s...',
            $selectedProvider,
            count($toolCalls),
            count($toolCalls) === 1 ? '' : 's',
        ));

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(
            $toolCalls,
            $provider,
            $snapshotEvery,
            false,
            [],
            false,
            $injectLossAfter,
        );

        $deadline = time() + $waitSeconds;

        while (time() < $deadline) {
            $workflow->refresh();

            if ($workflow->completed() || $workflow->failed()) {
                break;
            }

            sleep(1);
        }

        if ($workflow->failed()) {
            $this->error('Workflow failed.');

            return self::FAILURE;
        }

        if (! $workflow->completed()) {
            $this->warn(sprintf(
                'Workflow still running after %d seconds; check Waterline for progress.',
                $waitSeconds,
            ));

            return self::FAILURE;
        }

        $output = $workflow->output();
        $snapshotLifecycle = $this->snapshotLifecycle($workflow);
        $expectedSnapshots = $snapshotEvery > 0
            ? intdiv(count($toolCalls), $snapshotEvery)
            : 0;

        if ($snapshotLifecycle['created'] !== $expectedSnapshots
            || $snapshotLifecycle['cleaned'] !== $snapshotLifecycle['created']
            || ($output['latest_snapshot'] ?? null) !== null) {
            $this->error(sprintf(
                'Snapshot lifecycle incomplete. expected=%d created=%d cleaned=%d retained=%s',
                $expectedSnapshots,
                $snapshotLifecycle['created'],
                $snapshotLifecycle['cleaned'],
                ($output['latest_snapshot'] ?? null) === null ? 'none' : 'yes',
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Workflow complete. provider=%s sandbox=%s recoveries=%d snapshots=created:%d,cleaned:%d,retained:none',
            $output['provider'] ?? '?',
            $output['sandbox_id'] ?? '?',
            (int) ($output['recovery_count'] ?? 0),
            $snapshotLifecycle['created'],
            $snapshotLifecycle['cleaned'],
        ));

        foreach (($output['tool_results'] ?? []) as $i => $result) {
            $this->line(sprintf(
                '  [%d] exit=%d stdout=%s',
                $i + 1,
                (int) ($result['exit_code'] ?? -1),
                trim((string) ($result['stdout'] ?? '')),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{created: int, cleaned: int}
     */
    private function snapshotLifecycle(WorkflowStub $workflow): array
    {
        $run = $workflow->run();

        if ($run === null) {
            return ['created' => 0, 'cleaned' => 0];
        }

        $completedActivities = $run->activityExecutions()
            ->where('status', ActivityStatus::Completed->value)
            ->whereIn('activity_class', [
                SnapshotSandboxActivity::class,
                DeleteSnapshotActivity::class,
            ])
            ->get(['activity_class'])
            ->countBy('activity_class');

        return [
            'created' => (int) $completedActivities->get(SnapshotSandboxActivity::class, 0),
            'cleaned' => (int) $completedActivities->get(DeleteSnapshotActivity::class, 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function demoToolCalls(): array
    {
        return [
            ['type' => 'write_file', 'args' => ['path' => 'README.md', 'contents' => "# durable sandbox demo\n"]],
            ['type' => 'shell', 'args' => ['command' => 'ls -1']],
            ['type' => 'read_file', 'args' => ['path' => 'README.md']],
            ['type' => 'shell', 'args' => ['command' => 'echo session-complete']],
        ];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            $this->warn("Ignoring invalid {$name} value; expected a positive integer.");

            return null;
        }

        return (int) $value;
    }
}
