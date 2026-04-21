<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\StartOptions;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

class StartWorkflowTool extends Tool
{
    protected string $name = 'start_workflow';

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Start a configured Durable Workflow v2 workflow asynchronously and return its instance/run IDs.
        
        The workflow executes through the v2 durable runtime. Use `get_workflow_result`
        to poll status/output and `get_workflow_history` to inspect recent typed history.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'workflow' => ['required', 'string'],
            'arguments' => ['nullable', 'array'],
            'args' => ['nullable', 'array'],
            'instance_id' => ['nullable', 'string', 'max:191'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'business_key' => ['nullable', 'string', 'max:191'],
            'visibility_labels' => ['nullable', 'array'],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'duplicate_start_policy' => ['nullable', 'string', 'in:reject_duplicate,return_existing_active'],
        ]);

        $workflowKey = $data['workflow'];
        $args = $this->normalizeArguments(Arr::get($data, 'arguments', Arr::get($data, 'args', [])));
        $instanceId = $data['instance_id'] ?? null;
        $businessKey = $data['business_key'] ?? ($data['external_id'] ?? null);

        // Resolve workflow class from config mapping or FQCN
        $workflowClass = $this->resolveWorkflowClass($workflowKey);

        if ($workflowClass === null) {
            return Response::error("Unknown workflow: {$workflowKey}. Check available workflows in config/workflow_mcp.php.");
        }

        // Validate the class exists and is a Workflow subclass
        if (! class_exists($workflowClass)) {
            return Response::error("Workflow class not found: {$workflowClass}");
        }

        if (! is_subclass_of($workflowClass, Workflow::class)) {
            return Response::error("Class {$workflowClass} is not a valid Workflow.");
        }

        try {
            $startOptions = new StartOptions(
                duplicateStartPolicy: DuplicateStartPolicy::from(
                    $data['duplicate_start_policy'] ?? DuplicateStartPolicy::RejectDuplicate->value,
                ),
                businessKey: $businessKey,
                labels: $data['visibility_labels'] ?? [],
                memo: $data['memo'] ?? [],
                searchAttributes: $data['search_attributes'] ?? [],
            );

            $stub = WorkflowStub::make($workflowClass, $instanceId);

            // Start the workflow asynchronously with the provided arguments.
            $result = $stub->start(...[...$args, $startOptions]);
            $stub->refresh();

            return Response::structured([
                'workflow_id' => $stub->id(),
                'run_id' => $stub->runId() ?? $result->runId(),
                'workflow' => $workflowKey,
                'workflow_class' => $workflowClass,
                'workflow_type' => $result->workflowType(),
                'status' => $stub->status(),
                'running' => $stub->running(),
                'business_key' => $businessKey,
                'duplicate_start_policy' => $startOptions->duplicateStartPolicy->value,
                'command' => [
                    'id' => $result->commandId(),
                    'sequence' => $result->commandSequence(),
                    'status' => $result->status(),
                    'outcome' => $result->outcome(),
                    'rejection_reason' => $result->rejectionReason(),
                ],
                'message' => 'Workflow accepted. Use get_workflow_result to poll status/output and get_workflow_history for recent typed history.',
            ]);
        } catch (\Throwable $e) {
            return Response::error("Failed to start workflow: {$e->getMessage()}");
        }
    }

    /**
     * Resolve a workflow class from a key or FQCN.
     */
    protected function resolveWorkflowClass(string $key): ?string
    {
        // First check the config mapping
        $mapped = config("workflow_mcp.workflows.{$key}");
        if (is_string($mapped)) {
            return $mapped;
        }

        if (is_array($mapped) && is_string($mapped['class'] ?? null)) {
            return $mapped['class'];
        }

        // If FQCN is allowed, check if the key looks like a class name
        if (config('workflow_mcp.allow_fqcn', false) && str_contains($key, '\\')) {
            return $key;
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeArguments(mixed $arguments): array
    {
        if (! is_array($arguments)) {
            return [];
        }

        return array_is_list($arguments)
            ? $arguments
            : array_values($arguments);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        $availableWorkflows = array_keys(config('workflow_mcp.workflows', []));
        $workflowList = implode(', ', $availableWorkflows);

        return [
            'workflow' => $schema->string()
                ->description("The workflow key or class to start. Available workflows: {$workflowList}"),

            'arguments' => $schema->array()
                ->description('Ordered arguments for the workflow handle() method. Prefer this over the legacy args object.'),

            'args' => $schema->object()
                ->description('Legacy arguments object. Values are passed to handle() in object insertion order.'),

            'instance_id' => $schema->string()
                ->description('Optional caller-supplied workflow instance id for idempotency. Must be URL-safe.'),

            'business_key' => $schema->string()
                ->description('Optional operator-facing business key copied into Waterline visibility.'),

            'external_id' => $schema->string()
                ->description('Legacy alias for business_key. Prefer business_key for new clients.'),

            'visibility_labels' => $schema->object()
                ->description('Optional string labels copied to the workflow run for Waterline filtering.'),

            'memo' => $schema->object()
                ->description('Optional non-indexed JSON metadata copied to the workflow run.'),

            'search_attributes' => $schema->object()
                ->description('Optional typed search attributes for operator visibility.'),

            'duplicate_start_policy' => $schema->string()
                ->enum([
                    DuplicateStartPolicy::RejectDuplicate->value,
                    DuplicateStartPolicy::ReturnExistingActive->value,
                ])
                ->description('How to handle an already-active caller-supplied instance id.'),
        ];
    }
}
