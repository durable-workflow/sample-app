<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Illuminate\Console\Command;
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
        $this->info(sprintf(
            'Workflow complete. provider=%s sandbox=%s recoveries=%d snapshots=%s',
            $output['provider'] ?? '?',
            $output['sandbox_id'] ?? '?',
            (int) ($output['recovery_count'] ?? 0),
            $output['latest_snapshot'] ?? 'none',
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
