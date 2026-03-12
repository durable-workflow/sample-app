# Durable Workflow Sample App

This is a sample Laravel 12 application with example workflows that you can run inside a GitHub codespace.

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
Start the queue worker. This will enable the processing of workflows and activities.

```bash
php artisan queue:work
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
You can view the waterline dashboard at https://[your-codespace-name]-18080.preview.app.github.dev/waterline/dashboard.

<img src="https://user-images.githubusercontent.com/1130888/233669600-3340ada6-5f73-4602-8d82-a81a9d43f883.png" alt="image" width="600">

### Step 9
Run the workflow and activity tests.

```bash
php artisan test
```

That's it! You can now create and test workflows.

----

#### More Workflows to Explore

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

This sample app includes an MCP (Model Context Protocol) server that allows AI clients (ChatGPT, Claude, Cursor, etc.) to start and monitor workflows.

##### Endpoint

The MCP server is available at: `/mcp/workflows`

##### Available Tools

| Tool | Description |
|------|-------------|
| `list_workflows` | Discover available workflows and view recent workflow runs |
| `start_workflow` | Start a workflow asynchronously and get a workflow ID |
| `get_workflow_result` | Check workflow status and retrieve output when completed |

##### Configuration

Available workflows are defined in `config/workflow_mcp.php`. By default, the following workflows are exposed:

- `simple` → `App\Workflows\Simple\SimpleWorkflow`
- `prism` → `App\Workflows\Prism\PrismWorkflow`

To add more workflows, update the config file:

```php
'workflows' => [
    'simple' => App\Workflows\Simple\SimpleWorkflow::class,
    'prism' => App\Workflows\Prism\PrismWorkflow::class,
    'my_workflow' => App\Workflows\MyWorkflow::class,
],
```

##### Example Usage

An AI client would typically:

1. Call `list_workflows` to see available workflows
2. Call `start_workflow` with `{"workflow": "simple"}` 
3. Receive a `workflow_id` in the response
4. Poll `get_workflow_result` with the `workflow_id` until status is `WorkflowCompletedStatus`
5. Read the `output` field for the workflow result
