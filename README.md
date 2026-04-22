# Durable Workflow Sample App

This is a sample Laravel 13 application built on **Durable Workflow 2.0 (alpha)** with example workflows that you can run inside a GitHub Codespace.

> **Looking for the Laravel 12 / Durable Workflow 1.x version?** It's preserved on the [`Laravel-12` branch](https://github.com/durable-workflow/sample-app/tree/Laravel-12). Older blog posts and tutorials that reference v1 patterns (e.g. `Workflow\Workflow`, `yield activity(...)`, `Workflow\Activity`) target that branch.

### Step 1
Create a codespace from the main branch of this repo.

<img src="https://user-images.githubusercontent.com/1130888/233664377-f300ad50-5436-4bb8-b172-c52e12047264.png" alt="image" width="300">

### Step 2
Once the codespace has been created, wait for the codespace to build. This should take between 5 to 10 minutes.


### Step 3
Once it is done. You will see the editor and the terminal at the bottom.

<img src="https://user-images.githubusercontent.com/1130888/233665550-1a4f2098-2919-4108-ac9f-bef1a9f2f47c.png" alt="image" width="400">

### Step 4
Run the init command to setup the app, install extra dependencies and run the migrations.

```bash
php artisan app:init
```

### Step 5
Start the server. This will enable the processing of workflows and activities.

```bash
composer run dev
```

### Step 6
Create a new terminal window.

<img src="https://user-images.githubusercontent.com/1130888/233666917-029247c7-9e6c-46de-b304-27473fd34517.png" alt="image" width="200">

### Step 7
Start the example workflow inside the new terminal window.

```bash
php artisan app:workflow
```

### Step 8
You can view the waterline dashboard at https://didactic-system-7v59r6xpjj3w7v-18080.app.github.dev/waterline/dashboard.

<img src="https://user-images.githubusercontent.com/1130888/233669600-3340ada6-5f73-4602-8d82-a81a9d43f883.png" alt="image" width="600">

### Step 9
Run the workflow and activity tests.

```bash
php artisan test
```

That's it! You can now create and test workflows.

----

#### Sample Index

Use this index when you want a specific Durable Workflow pattern instead of another happy-path snippet.

| Goal | Workflow | Command | MCP key |
|------|----------|---------|---------|
| Learn the smallest v2 workflow/activity shape | `App\Workflows\Simple\SimpleWorkflow` | `php artisan app:workflow` | `simple` |
| Measure durable elapsed time without replay drift | `App\Workflows\Elapsed\ElapsedTimeWorkflow` | `php artisan app:elapsed` | `elapsed` |
| Coordinate work across Laravel app boundaries | `App\Workflows\Microservice\MicroserviceWorkflow` | `php artisan app:microservice` | `microservice` |
| Run browser automation and collect generated artifacts | `App\Workflows\Playwright\CheckConsoleErrorsWorkflow` | `php artisan app:playwright` | `playwright` |
| Start from an external webhook and wait for a signal | `App\Workflows\Webhooks\WebhookWorkflow` | `php artisan app:webhook` | `webhook` |
| Wrap an AI activity loop in durable retry/validation | `App\Workflows\Prism\PrismWorkflow` | `php artisan app:prism` | `prism` |
| Build a signal-driven AI agent with compensation | `App\Workflows\Ai\AiWorkflow` | `php artisan app:ai` | `ai` |

In addition to the basic example workflow, you can try these other workflows included in this sample app:

* `php artisan app:elapsed` – Demonstrates how to correctly track start and end times to measure execution duration.

* `php artisan app:microservice` – A fully working example of a workflow that spans multiple Laravel applications using a shared database and queue.

* `php artisan app:playwright` – Runs a Playwright automation, captures a WebM video, encodes it to MP4 using FFmpeg, and then cleans up the WebM file.

* `php artisan app:webhook` – Showcases how to use the built-in webhook system for triggering workflows externally.

* `php artisan app:prism` - Uses Prism to build a durable AI agent loop. It asks an LLM to generate user profiles and hobbies, validates the result, and retries until the data meets business rules.

* `php artisan app:ai` - NEW! Uses Laravel AI SDK to build a durable travel agent. The agent asks questions and books hotels, flights, and rental cars. If any errors occur, the workflow ensures all bookings are canceled.

Try them out to see workflows in action across different use cases!

----

#### MCP Integration for AI Clients

This sample app includes an MCP (Model Context Protocol) server that allows AI clients (ChatGPT, Claude, Cursor, etc.) to start and monitor Durable Workflow v2 workflows. Treat it as the agent-operable companion to Waterline: humans can inspect `/waterline/dashboard`, while AI clients receive structured workflow IDs, run IDs, statuses, recent typed history, and failure summaries.

The MCP server is named `Durable Workflow`.

It is not a separate daemon in this repo. The server is exposed by the Laravel application itself, so once the app is running, the MCP route is live as part of the normal HTTP server.

##### Endpoint

The MCP server is available at: `/mcp/workflows`

##### Running It

To make the MCP server available locally:

1. Run `php artisan app:init`
2. Start the queue worker with `php artisan queue:work redis --queue=default,activity`
3. Start the Laravel app with `php artisan serve`
4. Connect your MCP client to `http://localhost:8000/mcp/workflows`

If you prefer Docker, run `docker compose up --build` and then connect to `http://localhost:8000/mcp/workflows` once the containers are healthy.

##### Available Tools

| Tool | Description |
|------|-------------|
| `list_workflows` | Discover configured workflow keys, credential requirements, status values, and recent v2 runs |
| `start_workflow` | Start a configured v2 workflow asynchronously and get a workflow instance ID plus run ID |
| `get_workflow_result` | Check workflow status, output, visibility metadata, and latest failure summary |
| `get_workflow_history` | Inspect a bounded slice of typed v2 history events and latest durable failures |

##### Configuration

Available workflows are defined in `config/workflow_mcp.php`. By default, every workflow in the sample index is exposed:

- `simple` → `App\Workflows\Simple\SimpleWorkflow`
- `elapsed` → `App\Workflows\Elapsed\ElapsedTimeWorkflow`
- `microservice` → `App\Workflows\Microservice\MicroserviceWorkflow`
- `playwright` → `App\Workflows\Playwright\CheckConsoleErrorsWorkflow` (requires local Playwright/Node/FFmpeg setup)
- `webhook` → `App\Workflows\Webhooks\WebhookWorkflow` (waits for the `ready` signal)
- `prism` → `App\Workflows\Prism\PrismWorkflow` (requires `OPENAI_API_KEY`)
- `ai` → `App\Workflows\Ai\AiWorkflow` (requires `OPENAI_API_KEY`, then accepts `send` signals and `receive` updates)

To add more workflows, update the config file:

```php
'workflows' => [
    'simple' => [
        'class' => App\Workflows\Simple\SimpleWorkflow::class,
        'description' => 'Small deterministic workflow.',
        'pattern' => 'deterministic activity chain',
        'command' => 'php artisan app:workflow',
        'requires' => [],
        'arguments' => [],
    ],
    'my_workflow' => [
        'class' => App\Workflows\MyWorkflow::class,
        'description' => 'What an agent should know before starting it.',
        'requires' => ['EXTERNAL_API_KEY'],
        'arguments' => [
            ['name' => 'customer_id', 'type' => 'string'],
        ],
    ],
],
```

Class-string mappings are still accepted for small local experiments, but the array form gives agents safer discovery metadata.

##### Example Usage

An AI client would typically:

1. Call `list_workflows` to see available workflows
2. Call `start_workflow` with `{"workflow": "simple", "business_key": "demo-001"}`
3. Receive `workflow_id` and `run_id` in the response
4. Poll `get_workflow_result` with the `workflow_id` until status is `completed`
5. Read the `output` field for the workflow result
6. If status is `failed` or `waiting` longer than expected, call `get_workflow_history` with the `run_id`
