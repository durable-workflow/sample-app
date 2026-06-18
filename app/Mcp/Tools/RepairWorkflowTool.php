<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Workflow\V2\WorkflowStub;

class RepairWorkflowTool extends Tool
{
    protected string $name = 'repair_workflow';

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Request the built-in Durable Workflow v2 repair command for the selected workflow run.

        Use this only after `diagnose_workflow` reports repair attention or a task
        problem. The result is structured even when the repair is refused or not needed.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'workflow_id' => ['nullable', 'string'],
            'run_id' => ['nullable', 'string'],
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
                'accepted' => false,
                'mutation' => $this->mutationEnvelope(false, 'workflow_not_found'),
                'root_cause' => [
                    'schema' => 'durable-workflow.v2.agent-root-cause',
                    'version' => 1,
                    'category' => 'workflow_not_found',
                    'reason' => 'No durable workflow instance or run matched the supplied identifier.',
                    'source' => [
                        'kind' => 'workflow',
                        'id' => null,
                    ],
                    'retryable' => false,
                    'severity' => 'error',
                    'actionable' => false,
                ],
                'remediation' => $this->remediationEnvelope(
                    'rediscover_workflow',
                    'Call list_workflows or get_workflow_result with a current identifier before requesting repair.',
                    false,
                    'No matching run exists to repair.',
                ),
            ]);
        }

        $result = $workflow->attemptRepair();
        $workflow->refresh();

        $accepted = $result->accepted();
        $outcome = $result->outcome();
        $rejectionReason = $result->rejectionReason();

        return Response::structured([
            'found' => true,
            'workflow_id' => $workflow->id(),
            'run_id' => $workflow->runId() ?? $result->runId(),
            'status' => $workflow->status(),
            'accepted' => $accepted,
            'mutation' => $this->mutationEnvelope($accepted, $rejectionReason),
            'command' => [
                'id' => $result->commandId(),
                'sequence' => $result->commandSequence(),
                'type' => $result->type(),
                'status' => $result->status(),
                'outcome' => $outcome,
                'rejection_reason' => $rejectionReason,
                'target_scope' => $result->targetScope(),
                'requested_run_id' => $result->requestedRunId(),
                'resolved_run_id' => $result->resolvedRunId(),
            ],
            'remediation' => $this->repairResultRemediation($accepted, $outcome, $rejectionReason),
            'next_actions' => $this->nextActions($accepted, $outcome, $rejectionReason),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mutationEnvelope(bool $applied, ?string $reason): array
    {
        return [
            'schema' => 'durable-workflow.v2.safe-mutation',
            'version' => 1,
            'tool' => 'repair_workflow',
            'operation' => 'repair',
            'safe' => true,
            'applied' => $applied,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repairResultRemediation(bool $accepted, ?string $outcome, ?string $rejectionReason): array
    {
        if (! $accepted) {
            return $this->remediationEnvelope(
                'repair_refused',
                'The workflow repair command was refused; use the rejection reason to choose the next inspection step.',
                false,
                $rejectionReason ?? 'repair_rejected',
            );
        }

        if ($outcome === 'repair_dispatched') {
            return $this->remediationEnvelope(
                'repair_dispatched',
                'A repair task was dispatched. Poll get_workflow_result and diagnose_workflow again to confirm progress.',
                true,
                null,
            );
        }

        return $this->remediationEnvelope(
            'repair_not_needed',
            'The repair command was accepted, but no task needed redispatch.',
            true,
            null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function remediationEnvelope(
        string $classification,
        string $summary,
        bool $repairAllowed,
        ?string $repairReason,
    ): array {
        return [
            'schema' => 'durable-workflow.v2.agent-remediation',
            'version' => 1,
            'classification' => $classification,
            'summary' => $summary,
            'automatic_repair' => [
                'tool' => 'repair_workflow',
                'allowed' => $repairAllowed,
                'reason' => $repairReason,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function nextActions(bool $accepted, ?string $outcome, ?string $rejectionReason): array
    {
        if (! $accepted) {
            return [
                [
                    'code' => 'diagnose_workflow',
                    'label' => 'Call diagnose_workflow to classify why repair is not currently applicable.',
                    'tool' => 'diagnose_workflow',
                    'mutation' => false,
                    'reason' => $rejectionReason,
                ],
                [
                    'code' => 'inspect_history',
                    'label' => 'Call get_workflow_history to inspect the durable event trail before changing code or inputs.',
                    'tool' => 'get_workflow_history',
                    'mutation' => false,
                ],
            ];
        }

        if ($outcome === 'repair_dispatched') {
            return [
                [
                    'code' => 'poll_result',
                    'label' => 'Poll get_workflow_result until the repaired task makes progress or records a new failure.',
                    'tool' => 'get_workflow_result',
                    'mutation' => false,
                ],
            ];
        }

        return [
            [
                'code' => 'diagnose_workflow',
                'label' => 'Call diagnose_workflow again if the run still appears stuck after repair_not_needed.',
                'tool' => 'diagnose_workflow',
                'mutation' => false,
            ],
        ];
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
        ];
    }
}
