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
    private const ROOT_CAUSE_SCHEMA = 'durable-workflow.v2.agent-root-cause';

    private const REMEDIATION_SCHEMA = 'durable-workflow.v2.agent-remediation';

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
            $nextActions = [
                $this->action(
                    'list_workflows',
                    'List recent workflows to confirm the workflow_id or run_id.',
                    'list_workflows',
                ),
            ];

            return Response::structured([
                'found' => false,
                'workflow_id' => $data['workflow_id'] ?? null,
                'run_id' => $data['run_id'] ?? null,
                'diagnosis' => 'not_found',
                'root_cause' => $this->staticRootCause(
                    'workflow_not_found',
                    'No durable workflow instance or run matched the supplied identifier.',
                    'workflow',
                    null,
                    false,
                    'error',
                    false,
                ),
                'remediation' => $this->remediationEnvelope(
                    'rediscover_workflow',
                    'Rediscover the workflow surface and retry with a current workflow_id or run_id.',
                    false,
                    'No matching run exists to repair.',
                    $nextActions,
                ),
                'next_actions' => $nextActions,
            ]);
        }

        $run = $this->selectedRun($workflow);

        if ($run === null) {
            $nextActions = [
                $this->action(
                    'get_workflow_result',
                    'Poll the workflow result again after the run is created.',
                    'get_workflow_result',
                ),
            ];

            return Response::structured([
                'found' => true,
                'workflow_id' => $workflow->id(),
                'run_id' => null,
                'status' => $workflow->status(),
                'diagnosis' => 'run_not_available',
                'root_cause' => $this->staticRootCause(
                    'run_not_available',
                    'The workflow instance exists but has no selected run yet.',
                    'workflow',
                    $workflow->id(),
                    true,
                    'info',
                    true,
                ),
                'remediation' => $this->remediationEnvelope(
                    'wait_for_run_creation',
                    'Retry result inspection after the start command creates a run.',
                    false,
                    'Repair requires a selected run.',
                    $nextActions,
                ),
                'next_actions' => $nextActions,
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
        $diagnosis = $this->diagnosis($status, $facts, $latestFailure);
        $nextActions = $this->nextActions($status, $facts, $latestFailure);

        return Response::structured([
            'found' => true,
            'workflow_id' => $workflow->id(),
            'run_id' => $workflow->runId(),
            'diagnosis' => $diagnosis,
            'facts' => $facts,
            'latest_failure' => $latestFailure instanceof WorkflowFailure
                ? $this->failureSummary($latestFailure)
                : null,
            'recent_history' => $recentHistory,
            'root_cause' => $this->rootCause($diagnosis, $status, $facts, $latestFailure),
            'remediation' => $this->remediation($status, $facts, $latestFailure, $nextActions),
            'next_actions' => $nextActions,
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
     * @return array<int, array<string, mixed>>
     */
    private function nextActions(string $status, array $facts, ?WorkflowFailure $failure): array
    {
        $actions = [];

        if ($failure instanceof WorkflowFailure || $status === RunStatus::Failed->value) {
            $actions[] = $this->action(
                'inspect_history',
                'Call get_workflow_history with include_payloads=false to inspect durable failure context.',
                'get_workflow_history',
            );
            $actions[] = $this->action(
                'open_waterline',
                'Open Waterline for the selected run before attempting repair.',
            );
        }

        if ($facts['repair_attention'] === true || $facts['task_problem'] === true) {
            $repairAllowed = ! $this->terminalStatus($status);

            $actions[] = $this->action(
                'repair_workflow',
                $repairAllowed
                    ? 'Call repair_workflow to request the built-in v2 repair command for the selected run.'
                    : 'The selected run is terminal; inspect history and fix the workflow or activity source before starting a new run.',
                'repair_workflow',
                true,
                $repairAllowed,
                $repairAllowed ? null : 'terminal_run',
            );
            $actions[] = $this->action(
                'inspect_waterline_diagnostics',
                'Use Waterline diagnostics to confirm the repair attention reason and affected task.',
            );
        }

        if ($facts['continue_as_new_recommended'] === true) {
            $actions[] = $this->action(
                'plan_continue_as_new',
                'Plan a Continue-As-New boundary before history growth becomes operational debt.',
            );
        }

        if ($status === RunStatus::Waiting->value) {
            $waitKind = is_string($facts['wait_kind'] ?? null) ? $facts['wait_kind'] : null;
            $actions[] = $this->action(
                $waitKind === null ? 'inspect_wait' : 'inspect_wait_'.$this->normaliseCode($waitKind),
                $waitKind === null
                    ? 'Inspect recent history to identify what the workflow is waiting for.'
                    : "The workflow is waiting on {$waitKind}; send the expected external input or let the dependency complete.",
                $waitKind === null ? 'get_workflow_history' : null,
            );
        }

        if ($actions === []) {
            $actions[] = match ($status) {
                RunStatus::Completed->value => $this->action(
                    'read_output',
                    'Call get_workflow_result to read the completed workflow output.',
                    'get_workflow_result',
                ),
                RunStatus::Pending->value, RunStatus::Running->value => $this->action(
                    'poll_result',
                    'Poll get_workflow_result until the status changes or a wait/failure appears.',
                    'get_workflow_result',
                ),
                default => $this->action(
                    'inspect_history',
                    'Call get_workflow_history to inspect the durable event trail.',
                    'get_workflow_history',
                ),
            };
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function rootCause(string $diagnosis, string $status, array $facts, ?WorkflowFailure $failure): array
    {
        if ($failure instanceof WorkflowFailure) {
            $sourceKind = is_string($failure->source_kind) && $failure->source_kind !== ''
                ? $failure->source_kind
                : 'workflow';
            $category = $sourceKind === 'activity'
                ? 'activity_failure'
                : 'workflow_failure';
            $message = is_string($failure->message) && $failure->message !== ''
                ? $failure->message
                : 'The run recorded a durable failure.';

            return $this->staticRootCause(
                $category,
                $message,
                $sourceKind,
                $failure->source_id,
                $failure->non_retryable === true ? false : null,
                $failure->non_retryable === true ? 'error' : 'warning',
                true,
                [
                    'failure_category' => $failure->failure_category?->value,
                    'exception_class' => $failure->exception_class,
                    'handled' => $failure->handled,
                ],
            );
        }

        if ($facts['repair_attention'] === true || $facts['task_problem'] === true) {
            return $this->staticRootCause(
                'task_repair_attention',
                is_string($facts['repair_blocked_reason'] ?? null) && $facts['repair_blocked_reason'] !== ''
                    ? (string) $facts['repair_blocked_reason']
                    : 'The run summary reports a task or dispatch problem that may need repair.',
                'task_queue',
                is_string($facts['workflow_type'] ?? null) ? (string) $facts['workflow_type'] : null,
                true,
                'warning',
                ! $this->terminalStatus($status),
            );
        }

        if ($facts['continue_as_new_recommended'] === true) {
            return $this->staticRootCause(
                'history_growth_attention',
                'The run history is large enough that a Continue-As-New boundary is recommended.',
                'workflow_history',
                null,
                null,
                'warning',
                true,
            );
        }

        if ($status === RunStatus::Waiting->value) {
            $waitKind = is_string($facts['wait_kind'] ?? null) && $facts['wait_kind'] !== ''
                ? (string) $facts['wait_kind']
                : 'external_event';

            return $this->staticRootCause(
                'waiting_for_'.$this->normaliseCode($waitKind),
                "The workflow is waiting for {$waitKind}.",
                'workflow_wait',
                $waitKind,
                true,
                'info',
                true,
            );
        }

        return match ($status) {
            RunStatus::Completed->value => $this->staticRootCause(
                'none',
                'The workflow completed successfully.',
                'workflow',
                null,
                null,
                'info',
                false,
            ),
            RunStatus::Pending->value, RunStatus::Running->value => $this->staticRootCause(
                'in_progress',
                'The workflow is still making progress or waiting for the next worker poll.',
                'workflow',
                null,
                true,
                'info',
                true,
            ),
            RunStatus::Cancelled->value, RunStatus::Terminated->value => $this->staticRootCause(
                'closed_by_operator',
                'The workflow is closed and cannot be repaired in place.',
                'workflow',
                null,
                false,
                'info',
                false,
            ),
            default => $this->staticRootCause(
                $diagnosis === '' ? 'unknown' : $diagnosis,
                'The workflow state does not match a more specific diagnosis.',
                'workflow',
                null,
                null,
                'warning',
                true,
            ),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $nextActions
     * @return array<string, mixed>
     */
    private function remediation(string $status, array $facts, ?WorkflowFailure $failure, array $nextActions): array
    {
        $repairAllowed = ($facts['repair_attention'] === true || $facts['task_problem'] === true)
            && ! $this->terminalStatus($status);

        if ($failure instanceof WorkflowFailure) {
            $classification = $failure->non_retryable === true
                ? 'change_workflow_or_activity_source'
                : 'inspect_failure_then_retry_or_repair';
            $summary = $failure->non_retryable === true
                ? 'Fix the failing workflow or activity behavior, then start a new run or replay from durable history.'
                : 'Inspect durable history and use repair only if task repair attention is present.';
            $repairReason = $repairAllowed
                ? null
                : ($this->terminalStatus($status) ? 'terminal_run' : 'no_repair_attention');

            return $this->remediationEnvelope($classification, $summary, $repairAllowed, $repairReason, $nextActions);
        }

        if ($repairAllowed) {
            return $this->remediationEnvelope(
                'run_repair_command',
                'Request the built-in repair command and then poll the result and history surfaces again.',
                true,
                null,
                $nextActions,
            );
        }

        if ($status === RunStatus::Waiting->value) {
            return $this->remediationEnvelope(
                'provide_expected_input_or_wait',
                'Supply the expected signal, update, activity result, timer, or child-workflow completion through the documented workflow surface.',
                false,
                'waiting_state_is_not_task_repair',
                $nextActions,
            );
        }

        if ($facts['continue_as_new_recommended'] === true) {
            return $this->remediationEnvelope(
                'plan_continue_as_new',
                'Add or move a Continue-As-New boundary before the run accumulates operationally expensive history.',
                false,
                'history_growth_is_design_remediation',
                $nextActions,
            );
        }

        return match ($status) {
            RunStatus::Completed->value => $this->remediationEnvelope(
                'no_action_needed',
                'Read the output or export history if durable evidence is needed.',
                false,
                'completed_run',
                $nextActions,
            ),
            RunStatus::Pending->value, RunStatus::Running->value => $this->remediationEnvelope(
                'continue_polling',
                'Poll result and diagnosis again until the run completes, waits, or records a failure.',
                false,
                'no_repair_attention',
                $nextActions,
            ),
            default => $this->remediationEnvelope(
                'inspect_history',
                'Inspect history and operator diagnostics before choosing a mutation.',
                false,
                'classification_unknown',
                $nextActions,
            ),
        };
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function staticRootCause(
        string $category,
        string $reason,
        string $sourceKind,
        ?string $sourceId,
        ?bool $retryable,
        string $severity,
        bool $actionable,
        array $extra = [],
    ): array {
        return [
            'schema' => self::ROOT_CAUSE_SCHEMA,
            'version' => 1,
            'category' => $category,
            'reason' => $reason,
            'source' => [
                'kind' => $sourceKind,
                'id' => $sourceId,
            ],
            'retryable' => $retryable,
            'severity' => $severity,
            'actionable' => $actionable,
            ...$extra,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $nextActions
     * @return array<string, mixed>
     */
    private function remediationEnvelope(
        string $classification,
        string $summary,
        bool $repairAllowed,
        ?string $repairReason,
        array $nextActions,
    ): array {
        return [
            'schema' => self::REMEDIATION_SCHEMA,
            'version' => 1,
            'classification' => $classification,
            'summary' => $summary,
            'automatic_repair' => [
                'tool' => 'repair_workflow',
                'allowed' => $repairAllowed,
                'reason' => $repairReason,
            ],
            'next_actions' => $nextActions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function action(
        string $code,
        string $label,
        ?string $tool = null,
        bool $mutation = false,
        ?bool $allowed = null,
        ?string $reason = null,
    ): array {
        return array_filter([
            'code' => $code,
            'label' => $label,
            'tool' => $tool,
            'mutation' => $mutation,
            'allowed' => $allowed,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function terminalStatus(string $status): bool
    {
        return in_array($status, [
            RunStatus::Completed->value,
            RunStatus::Failed->value,
            RunStatus::Cancelled->value,
            RunStatus::Terminated->value,
        ], true);
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
