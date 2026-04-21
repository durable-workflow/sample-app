<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\ConfiguredV2Models;

class ListWorkflowsTool extends Tool
{
    protected string $name = 'list_workflows';

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        List available Durable Workflow v2 workflow types and optionally show recent workflow runs.
        
        Use this tool first so an AI client can choose a configured workflow key,
        see which examples need external credentials, and inspect recent v2 run status.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $data = $request->validate([
            'show_recent' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', 'string'],
        ]);

        $showRecent = $data['show_recent'] ?? false;
        $limit = $data['limit'] ?? 10;
        $statusFilter = $data['status'] ?? null;

        // Get available workflows from config
        $availableWorkflows = [];
        foreach (config('workflow_mcp.workflows', []) as $key => $definition) {
            $availableWorkflows[] = [
                'key' => $key,
                'class' => $this->workflowClass($definition),
                'description' => is_array($definition) ? ($definition['description'] ?? null) : null,
                'requires' => is_array($definition) && is_array($definition['requires'] ?? null)
                    ? array_values($definition['requires'])
                    : [],
                'arguments' => is_array($definition) && is_array($definition['arguments'] ?? null)
                    ? $definition['arguments']
                    : [],
            ];
        }

        $response = [
            'available_workflows' => $availableWorkflows,
            'allow_fqcn' => config('workflow_mcp.allow_fqcn', false),
            'workflow_id_kind' => 'workflow_instance_id',
            'run_id_kind' => 'workflow_run_id',
            'status_values' => array_map(static fn (RunStatus $status): string => $status->value, RunStatus::cases()),
        ];

        // Optionally include recent workflow runs
        if ($showRecent) {
            $query = ConfiguredV2Models::query('run_summary_model', WorkflowRunSummary::class)
                ->orderByDesc('sort_timestamp')
                ->orderByDesc('created_at')
                ->limit($limit);

            if ($statusFilter) {
                $query->where('status', $statusFilter);
            }

            $recentWorkflows = $query->get()->map(static function (WorkflowRunSummary $workflow): array {
                return [
                    'workflow_id' => $workflow->workflow_instance_id,
                    'run_id' => $workflow->id,
                    'workflow_type' => $workflow->workflow_type,
                    'workflow_class' => $workflow->workflow_class,
                    'status' => $workflow->status,
                    'is_current_run' => $workflow->is_current_run,
                    'business_key' => $workflow->business_key,
                    'history_event_count' => $workflow->history_event_count,
                    'history_size_bytes' => $workflow->history_size_bytes,
                    'repair_attention' => $workflow->repair_attention,
                    'task_problem' => $workflow->task_problem,
                    'wait_kind' => $workflow->wait_kind,
                    'started_at' => $workflow->started_at?->toIso8601String(),
                    'closed_at' => $workflow->closed_at?->toIso8601String(),
                    'created_at' => $workflow->created_at?->toIso8601String(),
                    'updated_at' => $workflow->updated_at?->toIso8601String(),
                ];
            });

            $response['recent_workflows'] = $recentWorkflows->all();
        }

        return Response::structured($response);
    }

    private function workflowClass(mixed $definition): ?string
    {
        if (is_string($definition)) {
            return $definition;
        }

        if (is_array($definition) && is_string($definition['class'] ?? null)) {
            return $definition['class'];
        }

        return null;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'show_recent' => $schema->boolean()
                ->description('Whether to include recent workflow runs in the response.'),

            'limit' => $schema->integer()
                ->description('Maximum number of recent workflows to return (default: 10, max: 50).'),

            'status' => $schema->string()
                ->description('Filter recent workflows by v2 status (e.g., "completed", "failed", "running").'),
        ];
    }
}
