<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\WorkflowStub;

class DiagnoseWorkflowTool extends Tool
{
    protected string $name = 'diagnose_workflow';

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Summarize durable workflow health facts and suggest safe next actions for AI operators.

        Use this when a workflow is failed, waiting longer than expected, or needs
        a compact machine-readable diagnostic before deciding whether to inspect
        full history, send a signal/update, or open Waterline.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'workflow_id' => ['nullable', 'string'],
            'run_id' => ['nullable', 'string'],
            'history_limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        if (empty($data['workflow_id']) && empty($data['run_id'])) {
            return Response::error('Provide workflow_id or run_id.');
        }

        try {
            $workflow = match (true) {
                ! empty($data['workflow_id']) && ! empty($data['run_id']) => WorkflowStub::loadSelection(
                    $data['workflow_id'],
                    $data['run_id'],
                ),
                ! empty($data['run_id']) => WorkflowStub::loadRun($data['run_id']),
                default => WorkflowStub::load($data['workflow_id']),
            };

            $workflow->refresh();
        } catch (ModelNotFoundException) {
            return Response::structured([
                'found' => false,
                'workflow_id' => $data['workflow_id'] ?? null,
                'run_id' => $data['run_id'] ?? null,
                'diagnosis' => 'not_found',
                'next_actions' => [
                    [
                        'code' => 'list_workflows',
                        'label' => 'List recent workflows to confirm the workflow_id or run_id.',
                    ],
                ],
            ]);
        }

        $run = $this->selectedRun($workflow);

        if ($run === null) {
            return Response::structured([
                'found' => true,
                'workflow_id' => $workflow->id(),
                'run_id' => null,
                'status' => $workflow->status(),
                'diagnosis' => 'run_not_available',
                'next_actions' => [
                    [
                        'code' => 'get_workflow_result',
                        'label' => 'Poll the workflow result again after the run is created.',
                    ],
                ],
            ]);
        }

        $summary = $this->selectedSummary($run);
        $status = $workflow->status();
        $latestFailure = $run->failures()->latest('created_at')->first();
        $historyLimit = $data['history_limit'] ?? 5;
        $recentHistory = $run->historyEvents()
            ->orderByDesc('sequence')
            ->limit($historyLimit)
            ->get()
            ->sortBy('sequence')
            ->values()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type->value,
                'payload_keys' => array_keys(is_array($event->payload) ? $event->payload : []),
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ])
            ->all();

        $facts = [
            'workflow_type' => $run->workflow_type,
            'workflow_class' => $run->workflow_class,
            'current_run_id' => $workflow->currentRunId(),
            'current_run_is_selected' => $workflow->currentRunIsSelected(),
            'business_key' => $workflow->businessKey(),
            'status' => $status,
            'running' => $workflow->running(),
            'history_event_count' => $summary?->history_event_count ?? $run->last_history_sequence,
            'history_size_bytes' => $summary?->history_size_bytes,
            'wait_kind' => $summary?->wait_kind,
            'wait_started_at' => $summary?->wait_started_at?->toIso8601String(),
            'wait_deadline_at' => $summary?->wait_deadline_at?->toIso8601String(),
            'liveness_state' => $summary?->liveness_state,
            'task_problem' => (bool) ($summary?->task_problem ?? false),
            'repair_attention' => (bool) ($summary?->repair_attention ?? false),
            'repair_blocked_reason' => $summary?->repair_blocked_reason,
            'continue_as_new_recommended' => (bool) ($summary?->continue_as_new_recommended ?? false),
            'last_progress_at' => $run->last_progress_at?->toIso8601String(),
            'next_task_at' => $summary?->next_task_at?->toIso8601String(),
        ];

        return Response::structured([
            'found' => true,
            'workflow_id' => $workflow->id(),
            'run_id' => $workflow->runId(),
            'diagnosis' => $this->diagnosis($status, $facts, $latestFailure),
            'facts' => $facts,
            'latest_failure' => $latestFailure instanceof WorkflowFailure
                ? $this->failureSummary($latestFailure)
                : null,
            'recent_history' => $recentHistory,
            'next_actions' => $this->nextActions($status, $facts, $latestFailure),
        ]);
    }

    private function selectedRun(WorkflowStub $workflow): ?WorkflowRun
    {
        $runId = $workflow->runId();

        if ($runId === null) {
            return null;
        }

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($runId);

        return $run;
    }

    private function selectedSummary(WorkflowRun $run): ?WorkflowRunSummary
    {
        /** @var WorkflowRunSummary|null $summary */
        $summary = ConfiguredV2Models::query('run_summary_model', WorkflowRunSummary::class)->find($run->id);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function diagnosis(string $status, array $facts, ?WorkflowFailure $failure): string
    {
        if ($failure instanceof WorkflowFailure || $status === RunStatus::Failed->value) {
            return 'failed';
        }

        if ($facts['repair_attention'] === true || $facts['task_problem'] === true) {
            return 'needs_repair_attention';
        }

        if ($facts['continue_as_new_recommended'] === true) {
            return 'history_growth_attention';
        }

        return match ($status) {
            RunStatus::Waiting->value => 'waiting_for_'.$this->normaliseCode((string) ($facts['wait_kind'] ?? 'external_event')),
            RunStatus::Completed->value => 'completed',
            RunStatus::Cancelled->value, RunStatus::Terminated->value => 'closed',
            RunStatus::Pending->value, RunStatus::Running->value => 'running',
            default => 'unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(WorkflowFailure $failure): array
    {
        return [
            'source_kind' => $failure->source_kind,
            'source_id' => $failure->source_id,
            'category' => $failure->failure_category?->value,
            'exception_class' => $failure->exception_class,
            'message' => $failure->message,
            'non_retryable' => $failure->non_retryable,
            'handled' => $failure->handled,
            'created_at' => $failure->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<int, array{code: string, label: string}>
     */
    private function nextActions(string $status, array $facts, ?WorkflowFailure $failure): array
    {
        $actions = [];

        if ($failure instanceof WorkflowFailure || $status === RunStatus::Failed->value) {
            $actions[] = [
                'code' => 'inspect_history',
                'label' => 'Call get_workflow_history with include_payloads=false to inspect durable failure context.',
            ];
            $actions[] = [
                'code' => 'open_waterline',
                'label' => 'Open Waterline for the selected run before attempting repair.',
            ];
        }

        if ($facts['repair_attention'] === true || $facts['task_problem'] === true) {
            $actions[] = [
                'code' => 'inspect_waterline_diagnostics',
                'label' => 'Use Waterline diagnostics to confirm the repair attention reason and affected task.',
            ];
        }

        if ($facts['continue_as_new_recommended'] === true) {
            $actions[] = [
                'code' => 'plan_continue_as_new',
                'label' => 'Plan a Continue-As-New boundary before history growth becomes operational debt.',
            ];
        }

        if ($status === RunStatus::Waiting->value) {
            $waitKind = is_string($facts['wait_kind'] ?? null) ? $facts['wait_kind'] : null;
            $actions[] = [
                'code' => $waitKind === null ? 'inspect_wait' : 'inspect_wait_'.$this->normaliseCode($waitKind),
                'label' => $waitKind === null
                    ? 'Inspect recent history to identify what the workflow is waiting for.'
                    : "The workflow is waiting on {$waitKind}; send the expected external input or let the dependency complete.",
            ];
        }

        if ($actions === []) {
            $actions[] = match ($status) {
                RunStatus::Completed->value => [
                    'code' => 'read_output',
                    'label' => 'Call get_workflow_result to read the completed workflow output.',
                ],
                RunStatus::Pending->value, RunStatus::Running->value => [
                    'code' => 'poll_result',
                    'label' => 'Poll get_workflow_result until the status changes or a wait/failure appears.',
                ],
                default => [
                    'code' => 'inspect_history',
                    'label' => 'Call get_workflow_history to inspect the durable event trail.',
                ],
            };
        }

        return $actions;
    }

    private function normaliseCode(string $value): string
    {
        $normalised = preg_replace('/[^a-z0-9]+/', '_', strtolower($value));

        return trim(is_string($normalised) ? $normalised : 'unknown', '_') ?: 'unknown';
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('Workflow instance ID returned by start_workflow. Optional when run_id is provided.'),

            'run_id' => $schema->string()
                ->description('Workflow run ID returned by start_workflow. Optional when workflow_id is provided.'),

            'history_limit' => $schema->integer()
                ->description('Maximum recent history events to include in the diagnosis (default: 5, max: 10).'),
        ];
    }
}
