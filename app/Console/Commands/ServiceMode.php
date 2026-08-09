<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Workflows\ServiceMode\WelcomeWorkflow;
use DurableWorkflow\WorkflowClientInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;

final class ServiceMode extends Command
{
    protected $signature = 'app:service-mode
        {name=Codespace : Name used by the welcome activities}
        {--workflow-id= : Use a specific workflow ID}
        {--json : Emit one machine-readable result}';

    protected $description = 'Run the Laravel service-mode welcome workflow';

    public function __construct(private readonly WorkflowClientInterface $workflows)
    {
        parent::__construct();
    }

    /** @throws JsonException */
    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $workflowId = $this->workflowId();
        $startedAt = hrtime(true);
        $handle = $this->workflows->startWorkflow(
            WelcomeWorkflow::TYPE,
            $workflowId,
            WelcomeWorkflow::PHP_TASK_QUEUE,
            [$name],
            memo: ['sample' => 'service-mode-welcome'],
        );
        $runId = property_exists($handle, 'selectedRunId') ? $handle->selectedRunId : null;

        if (! $this->option('json')) {
            $this->components->info("Started {$workflowId}; PHP and Python workers are processing it.");
        }

        $result = $handle->result(90.0, 0.25);
        $payload = [
            'workflow_id' => $workflowId,
            'run_id' => is_string($runId) && $runId !== '' ? $runId : null,
            'workflow_type' => WelcomeWorkflow::TYPE,
            'task_queue' => WelcomeWorkflow::PHP_TASK_QUEUE,
            'result' => $result,
            'result_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
        $payload['waterline_url'] = $this->waterlineUrl($workflowId, $payload['run_id']);

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info('The workflow completed after one PHP activity and one Python activity.');
            $this->line('Result: '.json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->line("Inspect this run in Waterline: {$payload['waterline_url']}");
        }

        return self::SUCCESS;
    }

    private function workflowId(): string
    {
        $requested = $this->option('workflow-id');
        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        return 'service-welcome-'.Str::lower((string) Str::ulid());
    }

    private function waterlineUrl(string $workflowId, ?string $runId): string
    {
        $url = rtrim((string) config('service-mode.waterline_url'), '/');
        $url .= '/flows/instances/'.rawurlencode($workflowId);

        return $runId === null ? $url : $url.'/runs/'.rawurlencode($runId);
    }
}
