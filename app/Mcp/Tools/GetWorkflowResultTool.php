<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\WorkflowStub;

class GetWorkflowResultTool extends Tool
{
    protected string $name = 'get_workflow_result';

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Fetch the status and, if completed, the output of a Durable Workflow v2 workflow.
        
        Use the workflow_id returned by `start_workflow` to check on a
        workflow's progress. Once the status is `completed`, the output field
        contains the workflow result. Failed runs include their latest durable
        failure summary when one has been recorded.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'workflow_id' => ['required', 'string'],
            'run_id' => ['nullable', 'string'],
            'include_recent_history' => ['nullable', 'boolean'],
            'history_limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $workflowId = $data['workflow_id'];
        $runId = $data['run_id'] ?? null;

        try {
            $workflow = WorkflowStub::loadSelection($workflowId, $runId);
            $workflow->refresh();
        } catch (ModelNotFoundException) {
            return Response::structured([
                'found' => false,
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'message' => "Workflow {$workflowId} not found.",
            ]);
        }

        try {
            $run = $this->selectedRun($workflow);
            $status = $workflow->status();
            $running = $workflow->running();

            $result = null;
            $error = null;

            // Get output if workflow is completed
            if ($workflow->completed()) {
                $result = $workflow->output();
            }

            // Get error details if workflow failed
            if ($workflow->failed() && $run !== null) {
                /** @var WorkflowFailure|null $failure */
                $failure = $run->failures()->latest('created_at')->first();
                $error = $failure !== null
                    ? [
                        'source_kind' => $failure->source_kind,
                        'source_id' => $failure->source_id,
                        'category' => $failure->failure_category?->value,
                        'exception_class' => $failure->exception_class,
                        'message' => $failure->message,
                        'non_retryable' => $failure->non_retryable,
                        'handled' => $failure->handled,
                    ]
                    : ['message' => 'Unknown error'];
            }

            $response = [
                'found' => true,
                'workflow_id' => $workflowId,
                'run_id' => $workflow->runId(),
                'current_run_id' => $workflow->currentRunId(),
                'current_run_is_selected' => $workflow->currentRunIsSelected(),
                'workflow_class' => $run?->workflow_class,
                'workflow_type' => $run?->workflow_type,
                'status' => $status,
                'running' => $running,
                'output' => $result,
                'error' => $error,
                'business_key' => $workflow->businessKey(),
                'visibility_labels' => $workflow->visibilityLabels(),
                'memo' => $workflow->memo(),
                'search_attributes' => $workflow->searchAttributes(),
                'created_at' => $run?->created_at?->toIso8601String(),
                'updated_at' => $run?->updated_at?->toIso8601String(),
                'started_at' => $run?->started_at?->toIso8601String(),
                'closed_at' => $run?->closed_at?->toIso8601String(),
            ];

            if (($data['include_recent_history'] ?? false) && $run !== null) {
                $limit = $data['history_limit'] ?? 10;
                $response['recent_history'] = $run->historyEvents()
                    ->orderByDesc('sequence')
                    ->limit($limit)
                    ->get()
                    ->sortBy('sequence')
                    ->values()
                    ->map(static fn ($event): array => [
                        'id' => $event->id,
                        'sequence' => $event->sequence,
                        'event_type' => $event->event_type->value,
                        'recorded_at' => $event->recorded_at?->toIso8601String(),
                    ])
                    ->all();
            }

            return Response::structured($response);
        } catch (\Throwable $e) {
            return Response::error("Failed to load workflow: {$e->getMessage()}");
        }
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

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow instance ID returned by start_workflow.'),

            'run_id' => $schema->string()
                ->description('Optional selected workflow run ID. Omit it to inspect the current run.'),

            'include_recent_history' => $schema->boolean()
                ->description('Whether to include a bounded list of recent typed history events.'),

            'history_limit' => $schema->integer()
                ->description('Maximum recent history events to return when include_recent_history is true (default: 10, max: 25).'),
        ];
    }
}
