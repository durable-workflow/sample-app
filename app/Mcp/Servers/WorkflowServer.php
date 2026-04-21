<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetWorkflowHistoryTool;
use App\Mcp\Tools\GetWorkflowResultTool;
use App\Mcp\Tools\ListWorkflowsTool;
use App\Mcp\Tools\StartWorkflowTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

class WorkflowServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Laravel Workflow Server';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        This server allows you to start and monitor Durable Workflow v2 workflows in the sample Laravel app.

        Use it as a machine-operable companion to Waterline: tool responses expose durable instance IDs,
        run IDs, typed statuses, recent history events, and failure summaries as structured JSON.

        ## Available Tools

        ### list_workflows
        Discover configured workflow keys, credential requirements, status values, and optionally recent workflow runs.

        ### start_workflow
        Start a configured v2 workflow asynchronously. Returns a workflow_id and run_id that you can use to check status.

        ### get_workflow_result
        Check the status of a workflow instance or selected run and retrieve output once completed.

        ### get_workflow_history
        Inspect a bounded slice of typed v2 history and latest failure facts for debugging.

        ## Typical Usage Pattern

        1. Call `list_workflows` to see what workflows are available.
        2. Call `start_workflow` with the workflow name and any required arguments.
        3. Store the returned `workflow_id`.
        4. Periodically call `get_workflow_result` with the `workflow_id` to check progress.
        5. When status becomes `completed`, read the `output` field for results.
        6. If status becomes `failed`, check the `error` field and call `get_workflow_history`.

        ## Status Values

        - `pending` - Workflow is queued for execution
        - `running` - Workflow is currently executing
        - `waiting` - Workflow is waiting for a timer, signal, update, child workflow, or activity result
        - `completed` - Workflow finished successfully and output is available
        - `failed` - Workflow encountered an error and failure details may be available
        - `cancelled` - Workflow was cancelled
        - `terminated` - Workflow was forcefully terminated
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListWorkflowsTool::class,
        StartWorkflowTool::class,
        GetWorkflowResultTool::class,
        GetWorkflowHistoryTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
