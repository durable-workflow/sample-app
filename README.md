# Durable Workflow Sample App

This is a sample Laravel 13 application built on **Durable Workflow 2.0 (alpha)** with example workflows that you can run inside a GitHub Codespace.

Pipeline smoke marker: pipeline-smoke-20260421155210

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
Start the queue worker. This will enable the processing of workflows and activities.

```bash
php artisan queue:work redis --queue=default,activity
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

#### Migrating from Durable Workflow 1.x

This app shipped on Durable Workflow 1.x (Laravel 12) until April 2026 — the [`Laravel-12` branch](https://github.com/durable-workflow/sample-app/tree/Laravel-12) is the snapshot of that state. The v2 API is straight-line and Fiber-driven; here are the patterns you need to update when porting your own workflows.

##### Workflows

```diff
-use Workflow\Workflow;
-use function Workflow\activity;
+use Workflow\V2\Workflow;
+use function Workflow\V2\activity;

 class OrderWorkflow extends Workflow
 {
-    public function execute(string $orderId)
+    public function handle(string $orderId): array
     {
-        $order = yield activity(LoadOrderActivity::class, $orderId);
-        yield activity(ChargeActivity::class, $order);
+        $order  = activity(LoadOrderActivity::class, $orderId);
+        $charge = activity(ChargeActivity::class, $order);

-        return $order;
+        return ['order' => $order, 'charge' => $charge];
     }
 }
```

Key differences:

- **Base class:** `extends Workflow\V2\Workflow` (not `Workflow\Workflow`).
- **Helper imports:** `use function Workflow\V2\{activity, sideEffect, await, timer, …}` — every helper has a `Workflow\V2\` namespaced equivalent.
- **No `yield`:** `activity(...)` is straight-line and returns the result directly. The Fiber-based runtime suspends transparently.
- **Entry method:** `handle()` is canonical (`execute()` is also accepted for transitional code).
- **`await`:** A single `await($condition, $timeout, $key)` replaces both `await()` and `awaitWithTimeout()`. Returns `true` if the condition was satisfied, `false` if the timeout fired.

##### Activities

```diff
-use Workflow\Activity;
+use Workflow\V2\Activity;

 class ChargeActivity extends Activity
 {
-    public function execute($order)
+    public function handle(array $order): array
     {
         // …
     }
 }
```

- **Base class:** `extends Workflow\V2\Activity`.
- **Method name:** `handle()` is canonical (`execute()` is still accepted).
- **Type hints:** Strongly recommended — argument and return types are part of the durable activity contract.

##### Signals & Updates

**Signals shifted from push to pull in v2.** v1 declared a method handler and the engine called it on signal arrival; v2 declares the signal contract at the class level and the workflow code blocks on `await($signalName)` to receive it:

```diff
-use Workflow\SignalMethod;
+use Workflow\V2\Attributes\Signal;
+use function Workflow\V2\await;

+#[Signal('approve', [['name' => 'reason', 'type' => 'string']])]
 class ApprovalWorkflow extends Workflow
 {
-    #[SignalMethod]
-    public function approve(string $reason): void {
-        $this->approved = true;
-        $this->reason = $reason;
-    }

-    public function execute() {
-        yield await(fn () => $this->approved);
-        return $this->reason;
+    public function handle(): string {
+        return await('approve');           // blocks for the signal, returns its arg
     }
 }
```

For workflows that need to drain *many* signals over time (chat-style loops), use `await($signalName, $timeout)` to bound each wait and check the return for `null` (timeout fired).

**Updates and queries** still use method-level attributes — `#[Workflow\UpdateMethod]` and `#[Workflow\QueryMethod]` carry over verbatim.

Invocation from outside the workflow uses explicit names:

```diff
-$workflow->send($payload);          // v1 magic method
-$result = $workflow->receive();
+$workflow->signal('send', $payload); // v2 explicit signal name
+$result = $workflow->update('receive');
```

##### Workflow stub

```diff
-use Workflow\WorkflowStub;
+use Workflow\V2\WorkflowStub;

 $stub = WorkflowStub::make(OrderWorkflow::class);
 $stub->start($orderId);
-while ($stub->running()) {
-    // tight loop
+while ($stub->refresh()->running()) {
+    usleep(100_000);
 }
```

`make()`, `load($workflowId)`, `start(...)`, `running()`, `completed()`, `failed()`, `output()`, `refresh()`, `signal($name, ...$args)`, `update($name, ...$args)` all carry over.

##### Webhooks

```diff
-use Workflow\Webhooks;
-Webhooks::routes();
+use App\Workflows\Webhooks\WebhookWorkflow;
+use Workflow\V2\Webhooks;
+Webhooks::routes([
+    'webhook-workflow' => WebhookWorkflow::class,
+]);
```

The v2 router takes an explicit alias → workflow class map (no auto-discovery). The `#[Webhook]` attribute on the workflow class continues to mark which signal/update methods are exposed via webhook URLs.

##### Inbox / Outbox

`Workflow\Inbox` and `Workflow\Outbox` are plain helper classes and work the same in v2 — see `app/Workflows/Ai/AiWorkflow.php` for a reference. Because the v2 base class has a `final` constructor, declare them as plain typed properties and lazy-initialize them inside the entry method (and in any signal handler that runs before `handle()`).

##### Saga compensation

The `addCompensation(callable)` / `compensate()` API is unchanged on the v2 `Workflow` base class. Compensation closures should call activities straight-line (no `yield from`):

```diff
-$this->addCompensation(fn () => yield activity(CancelHotelActivity::class, $hotel));
+$this->addCompensation(fn () => activity(CancelHotelActivity::class, $hotel));
```

----

#### MCP Integration for AI Clients

This sample app includes an MCP (Model Context Protocol) server that allows AI clients (ChatGPT, Claude, Cursor, etc.) to start and monitor Durable Workflow v2 workflows. Treat it as the agent-operable companion to Waterline: humans can inspect `/waterline/dashboard`, while AI clients receive structured workflow IDs, run IDs, statuses, recent typed history, and failure summaries.

##### Endpoint

The MCP server is available at: `/mcp/workflows`

##### Available Tools

| Tool | Description |
|------|-------------|
| `list_workflows` | Discover configured workflow keys, credential requirements, status values, and recent v2 runs |
| `start_workflow` | Start a configured v2 workflow asynchronously and get a workflow instance ID plus run ID |
| `get_workflow_result` | Check workflow status, output, visibility metadata, and latest failure summary |
| `get_workflow_history` | Inspect a bounded slice of typed v2 history events and latest durable failures |

##### Configuration

Available workflows are defined in `config/workflow_mcp.php`. By default, the following workflows are exposed:

- `simple` → `App\Workflows\Simple\SimpleWorkflow`
- `elapsed` → `App\Workflows\Elapsed\ElapsedTimeWorkflow`
- `prism` → `App\Workflows\Prism\PrismWorkflow` (requires `OPENAI_API_KEY`)

To add more workflows, update the config file:

```php
'workflows' => [
    'simple' => [
        'class' => App\Workflows\Simple\SimpleWorkflow::class,
        'description' => 'Small deterministic workflow.',
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
