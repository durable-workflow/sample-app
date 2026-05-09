<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\Sandbox\SandboxAgentWorkflow;
use Illuminate\Console\Command;
use Workflow\V2\WorkflowStub;

class Sandbox extends Command
{
    protected $signature = 'app:sandbox
        {--provider= : Sandbox provider override (defaults to config(\'sandbox.default\'))}
        {--snapshot-every=2 : Snapshot the workspace after every N tool calls (0 disables snapshots)}
        {--suspend-between : Idle-suspend the sandbox between tool calls and resume before the next one}';

    protected $description = 'Run the durable sandbox orchestration sample against the configured provider';

    public function handle(): int
    {
        $toolCalls = $this->demoToolCalls();
        $provider = $this->stringOption('provider');
        $snapshotEvery = (int) $this->option('snapshot-every');
        $suspend = (bool) $this->option('suspend-between');

        $this->line(sprintf(
            'Starting sandbox agent workflow against [%s] provider with %d tool call%s...',
            $provider ?? config('sandbox.default'),
            count($toolCalls),
            count($toolCalls) === 1 ? '' : 's',
        ));

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start($toolCalls, $provider, $snapshotEvery, $suspend);

        $deadline = time() + 60;

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
            $this->warn('Workflow still running after 60s; check Waterline for progress.');

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
}
