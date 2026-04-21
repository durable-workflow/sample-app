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
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\WorkflowStub;

class GetWorkflowHistoryTool extends Tool
{
    private const PAYLOAD_PREVIEW_BYTES = 4096;

    protected string $name = 'get_workflow_history';

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Inspect a bounded slice of Durable Workflow v2 typed history for debugging.

        Use this after `start_workflow` or `get_workflow_result` when an AI client needs
        durable facts about what happened, without scraping Waterline UI text.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'workflow_id' => ['nullable', 'string'],
            'run_id' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_payloads' => ['nullable', 'boolean'],
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
                'message' => 'Workflow run not found.',
            ]);
        }

        $run = $this->selectedRun($workflow);

        if ($run === null) {
            return Response::structured([
                'found' => true,
                'workflow_id' => $workflow->id(),
                'run_id' => null,
                'status' => $workflow->status(),
                'events' => [],
                'failures' => [],
            ]);
        }

        $limit = $data['limit'] ?? 50;
        $includePayloads = $data['include_payloads'] ?? false;

        $events = $run->historyEvents()
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get()
            ->sortBy('sequence')
            ->values()
            ->map(fn (WorkflowHistoryEvent $event): array => $this->historyEvent($event, $includePayloads))
            ->all();

        $failures = $run->failures()
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(static fn (WorkflowFailure $failure): array => [
                'id' => $failure->id,
                'source_kind' => $failure->source_kind,
                'source_id' => $failure->source_id,
                'category' => $failure->failure_category?->value,
                'exception_class' => $failure->exception_class,
                'message' => $failure->message,
                'non_retryable' => $failure->non_retryable,
                'handled' => $failure->handled,
                'created_at' => $failure->created_at?->toIso8601String(),
            ])
            ->all();

        return Response::structured([
            'found' => true,
            'workflow_id' => $workflow->id(),
            'run_id' => $workflow->runId(),
            'current_run_id' => $workflow->currentRunId(),
            'current_run_is_selected' => $workflow->currentRunIsSelected(),
            'workflow_class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'status' => $workflow->status(),
            'running' => $workflow->running(),
            'history_event_count' => $run->last_history_sequence,
            'returned_event_count' => count($events),
            'events_are_most_recent' => true,
            'payloads_included' => $includePayloads,
            'payload_preview_limit_bytes' => self::PAYLOAD_PREVIEW_BYTES,
            'events' => $events,
            'failures' => $failures,
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

    /**
     * @return array<string, mixed>
     */
    private function historyEvent(WorkflowHistoryEvent $event, bool $includePayload): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $encodedPayload = $this->encodePayload($payload);
        $payloadSizeBytes = strlen($encodedPayload);

        $summary = [
            'id' => $event->id,
            'sequence' => $event->sequence,
            'event_type' => $event->event_type->value,
            'workflow_task_id' => $event->workflow_task_id,
            'workflow_command_id' => $event->workflow_command_id,
            'recorded_at' => $event->recorded_at?->toIso8601String(),
            'payload_keys' => array_keys($payload),
            'payload_size_bytes' => $payloadSizeBytes,
        ];

        if ($includePayload) {
            $preview = substr($encodedPayload, 0, self::PAYLOAD_PREVIEW_BYTES);

            $summary['payload_preview'] = [
                'encoding' => 'json',
                'size_bytes' => $payloadSizeBytes,
                'preview_bytes' => strlen($preview),
                'truncated' => $payloadSizeBytes > self::PAYLOAD_PREVIEW_BYTES,
                'preview' => $preview,
            ];
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (is_string($encoded)) {
            return $encoded;
        }

        return '{}';
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

            'limit' => $schema->integer()
                ->description('Maximum events to return from the tail of typed history (default: 50, max: 100).'),

            'include_payloads' => $schema->boolean()
                ->description('Whether to include byte-limited JSON payload previews. Defaults to false; each preview is capped at 4096 bytes.'),
        ];
    }
}
