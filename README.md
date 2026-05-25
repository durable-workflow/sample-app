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
You can view the waterline dashboard at https://[your-codespace-name]-18080.preview.app.github.dev/waterline/dashboard.

<img src="https://user-images.githubusercontent.com/1130888/233669600-3340ada6-5f73-4602-8d82-a81a9d43f883.png" alt="image" width="600">

### Step 8a
Check the two observability surfaces separately:

| Surface | Use it for | Where to look |
|---------|------------|---------------|
| Waterline and the workflow database | Durable workflow truth: run status, typed history, signals, updates, timers, retries, failures, and operator actions. | `/waterline/dashboard`, selected run detail, and `php artisan workflow:v2:history-export` |
| Worker logs and SDK metrics | Runtime behavior: poll latency, task duration, exporter wiring, custom application metrics, and worker-side errors before they become durable failures. | Laravel logs for this PHP sample app; SDK metrics endpoints for external workers |

For this Laravel-only sample, Waterline proves that the durable run exists and shows what the engine committed. If you add a Python or other external worker, enable that worker's SDK metrics as a separate endpoint; those metrics will not appear inside Waterline unless you scrape them with your metrics stack.

Minimal Python worker Prometheus wiring looks like this:

```bash
pip install 'durable-workflow[prometheus]'
```

```python
from prometheus_client import start_http_server

from durable_workflow import Client, PrometheusMetrics, Worker

metrics = PrometheusMetrics()
start_http_server(9102)

async with Client("http://localhost:8080", token="secret", metrics=metrics) as client:
    worker = Worker(
        client,
        task_queue="default",
        workflows=[GreeterWorkflow],
        activities=[greet],
        metrics=metrics,
    )
    await worker.run()
```

Replace `GreeterWorkflow` and `greet` with the workflow and activity handlers registered by that worker.

Scrape `:9102/metrics` for `durable_workflow_worker_*` and `durable_workflow_client_*` series. Use Waterline for the matching workflow history and status.

### Step 9
Run the workflow and activity tests.

```bash
php artisan test
```

That's it! You can now create and test workflows.

----

### Run Locally With Docker

Prefer a local workstation over Codespaces? The repository ships a
`docker-compose.yml` that builds and runs the app, worker, MySQL, and Redis on
any host with Docker Engine and Docker Compose v2 installed.

```bash
# 1. Clone and enter the repo
git clone https://github.com/durable-workflow/sample-app.git
cd sample-app

# 2. (Optional) expose the app on a non-default port
export APP_PORT=18080

# 3. Build and start the stack. --wait blocks until health checks pass.
docker compose up -d --build --wait app worker

# 4. Run migrations against the shared sample database.
docker compose exec -T app php artisan migrate:fresh --force

# 5. Run the simplest deterministic sample end-to-end.
docker compose exec -T app php artisan app:workflow
```

Once the stack is up, Waterline is at `http://localhost:${APP_PORT:-8000}/waterline/dashboard`
and the MCP server is at `http://localhost:${APP_PORT:-8000}/mcp/workflows`.

To measure the full public sample-app surface, run the conformance harness after
the stack is healthy:

```bash
scripts/compose-conformance.sh --strict
```

The harness emits a JSON document with the sample-app commit, artifact versions,
timestamp, per-surface outcome, and any skipped surfaces. It runs the documented
artisan samples, browser checks for the app and Waterline, the MCP workflow API,
an API documentation check that compares the README's documented MCP tools and
workflow keys with the live endpoint, a Waterline/manual observation check using
`workflow:v2:history-export`, local sandbox lifecycle variants, sandbox recovery
injection, and the Prism/AI samples. The Prism check uses `OPENAI_API_KEY` for
the live model-backed AI surface. The travel-agent success and failure-injection
checks reuse one deterministic booking plan so the run proves signals, durable
assistant messages, booking activities, and compensation without spending extra
model calls on each failure variant.
Without AI credentials, `--strict` keeps the run non-passing and names the live
Prism surface as uncovered. Set `SAMPLE_APP_CONFORMANCE_ENV_FILE` when the key
lives in a dotenv file outside the repository; the wrapper also checks local
workspace-level dotenv files without printing credential values. Set
`DURABLE_SERVER_IMAGE`, `DURABLE_WORKFLOW_CLI_VERSION`, and
`DURABLE_WORKFLOW_PYTHON_SDK_VERSION` to override the wider published artifact
set recorded alongside the Composer pins. By default, the wrapper calls
`scripts/resolve-current-artifacts.sh`, which emits the current public
conformance tuple as shell assignments and preserves explicit overrides.
The wrapper passes the host checkout SHA into the app container as
`SAMPLE_APP_COMMIT`; set that variable explicitly when running from a source
archive or another environment without Git metadata.
The wrapper uses `http://app:8000` inside the Compose network so browser
activities running in the worker container can reach the app. Set
`SAMPLE_APP_CONFORMANCE_URL` when running against a different network address.
The wrapper runs strict by default; set `SAMPLE_APP_CONFORMANCE_ALLOW_SKIPS=1`
for local exploratory runs that should return zero while naming skipped surfaces.
`scripts/compose-smoke.sh` runs the deterministic samples first and then
delegates to this full harness by default; set `SAMPLE_APP_SMOKE_ONLY=1` only
for a deterministic preflight that intentionally leaves the broader public
sample-app surface uncovered.

Tear the stack down with `docker compose down -v --remove-orphans` when
finished. The deterministic Docker path is exercised on every push through the
`smoke` GitHub Actions workflow, and the full harness is available for release
and conformance checks that have the required credentials.

----

#### Sample Index

Use this index when you want a specific Durable Workflow pattern instead of another happy-path snippet.

| Goal | Workflow | Command | MCP key |
|------|----------|---------|---------|
| Learn the smallest v2 workflow/activity shape | `App\Workflows\Simple\SimpleWorkflow` | `php artisan app:workflow` | `simple` |
| Measure durable elapsed time without replay drift | `App\Workflows\Elapsed\ElapsedTimeWorkflow` | `php artisan app:elapsed` | `elapsed` |
| Coordinate work across Laravel app boundaries | `App\Workflows\Microservice\MicroserviceWorkflow` | `php artisan app:microservice` | `microservice` |
| Run browser automation and collect generated artifacts | `App\Workflows\Playwright\CheckConsoleErrorsWorkflow` | `php artisan app:playwright https://example.com` | `playwright` |
| Start from an external webhook and wait for a signal | `App\Workflows\Webhooks\WebhookWorkflow` | `php artisan app:webhook` | `webhook` |
| Wrap an AI activity loop in durable retry/validation | `App\Workflows\Prism\PrismWorkflow` | `php artisan app:prism` | `prism` |
| Build a signal-driven AI agent with compensation | `App\Workflows\Ai\AiWorkflow` | `php artisan app:ai` | `ai` |
| Orchestrate an ephemeral agent sandbox with durable lifecycle | `App\Workflows\Sandbox\SandboxAgentWorkflow` | `php artisan app:sandbox` | `sandbox` |
| Run the polyglot conformance smoke (Python same-language, PHP→Python, Python→PHP) | `App\Workflows\Polyglot\PhpToPythonWorkflow` plus Python-authored workflows in `polyglot/` | `docker compose -f polyglot/docker-compose.yml run --rm smoke` | `polyglot_php_to_python` |

#### Migrating from Durable Workflow 1.x

Porting a workflow from the v1 generator API to the v2 Fiber API is mechanical. The v1 sources live on the [`Laravel-12` branch](https://github.com/durable-workflow/sample-app/tree/Laravel-12); use it as a side-by-side reference while you migrate.

Workflow shape:

- Extend `Workflow\V2\Workflow` instead of `Workflow\Workflow`.
- Import helpers from the `Workflow\V2\` namespace: `use function Workflow\V2\{activity, sideEffect, await, timer};`.
- Replace `yield activity(...)` with a straight-line `activity(...)` call — the Fiber runtime suspends transparently.
- Rename the entry method from `execute(...)` to `handle(...)` and add return types.

Activities:

- Extend `Workflow\V2\Activity` and define `handle(...)` with typed parameters and return type. Activities are invoked by class name from workflow code, for example `activity(SimpleActivity::class)`.

Signals, updates, webhooks:

- Signals shifted from push to pull. Import the class-level contract attribute with `use Workflow\V2\Attributes\Signal;`, declare it as `#[Signal('name', [...])]`, and block on `await('name')` inside `handle()` to receive each delivery; `await('name', $timeout)` returns `null` on timeout for chat-style loops.
- `#[UpdateMethod]` and `#[QueryMethod]` carry over verbatim.
- From the outside, use explicit names: `$workflow->signal('name', $payload)` and `$workflow->update('name', ...)`.
- Webhook routing now takes an explicit alias map: `Workflow\V2\Webhooks::routes(['webhook-workflow' => WebhookWorkflow::class]);`.

Compensation closures:

- `addCompensation(callable)` and `compensate()` on the v2 `Workflow` base class are unchanged. Drop `yield from` inside the closures: `addCompensation(fn () => activity(CancelHotelActivity::class, $hotel));`.

Stub usage:

- Use `Workflow\V2\WorkflowStub`. The `make()`, `load()`, `start()`, `running()`, `output()`, `signal()`, and `update()` methods carry over; poll with `$stub->refresh()->running()` and a small `usleep(100_000)` between checks instead of a tight loop.

The `App\Workflows\Simple\SimpleWorkflow`, `App\Workflows\Webhooks\WebhookWorkflow`, and `App\Workflows\Ai\AiWorkflow` samples in this repo are the canonical references for the basic shape, webhook entry, and signal/update agent patterns respectively.

#### Message Streams

Use message streams when a workflow needs to publish or consume repeated messages without writing Durable Workflow storage rows directly. The v2 authoring API is exposed through `Workflow::inbox()`, `Workflow::outbox()`, and `Workflow::messages()`; those facades own `workflow_messages` rows and stream cursor advancement for the workflow run.

`App\Workflows\Ai\AiWorkflow` is the reference sample. It stores large assistant payloads in the app-owned `ai_workflow_messages` table, then publishes only a durable reference on the `ai.assistant` stream:

```php
$this->outbox(self::ASSISTANT_STREAM)
    ->sendReference(
        $this->workflowId(),
        $reference,
        correlationId: $reference,
        idempotencyKey: $reference,
        metadata: ['role' => 'assistant'],
    );
```

The `receive` update consumes the next assistant reply through the matching inbox stream:

```php
$streamMessage = $this->inbox(self::ASSISTANT_STREAM)
    ->receiveOne();
```

`receiveOne()` consumes the message and advances the durable stream cursor, so repeated receives deliver new replies instead of replaying old ones. Keep app tables as payload/reference stores; let Durable Workflow own `workflow_messages` and stream cursor advancement through the facade.

#### Sandbox Orchestration

Long-running coding agents need an ephemeral sandbox per session: a place to run shell commands, edit files, install dependencies, and recover when the sandbox vanishes mid-run. `App\Workflows\Sandbox\SandboxAgentWorkflow` is the durable reference for that lifecycle. It demonstrates five guarantees that every agent author would otherwise rebuild from scratch, and it does so against a swappable provider.

| Guarantee | Where it lives in the sample |
|-----------|------------------------------|
| Provision on demand | `ProvisionSandboxActivity` runs at workflow start. |
| Drive execution from agent intent | `DispatchToolCallActivity` carries each `{type, args}` tool call to the sandbox. |
| Persist state across long runs | `SnapshotSandboxActivity` writes a snapshot id every N tool calls; the id is durable across worker migrations. |
| Recover from sandbox loss | The workflow loop catches `SandboxGoneException` from a tool-call activity, calls `RestoreSandboxActivity` against the latest snapshot, and resumes. |
| Clean up at the right time | `DestroySandboxActivity` runs from a `try/finally` block, so success, cancel, and failure paths all tear the sandbox down. |

The workflow code never references a concrete provider class:

```php
$handle = activity(ProvisionSandboxActivity::class, $provider, $options);

try {
    foreach ($toolCalls as $call) {
        $results[] = activity(DispatchToolCallActivity::class, $handle, $call);
    }
} finally {
    activity(DestroySandboxActivity::class, $handle);
}
```

`config/sandbox.php` decides which `App\Sandbox\SandboxProvider` implementation `SandboxManager` returns. The repository ships two:

- `App\Sandbox\Providers\LocalSandboxProvider` — runs each tool call as a subprocess against a per-sandbox workspace directory under `storage_path('sandbox/workspaces')`. Snapshot is a tar of the workspace, restore extracts it. Useful for the demo, for CI, and for exercising the full lifecycle end-to-end without external credentials.
- `App\Sandbox\Providers\E2bSandboxProvider` — wraps the E2B Cloud sandbox HTTP API behind the same contract. 404 responses translate to `SandboxGoneException`, which the workflow recovers from automatically.

To add a third provider (Modal, Daytona, GKE Agent Sandbox, Bedrock AgentCore Runtime, or your own), implement `App\Sandbox\SandboxProvider` and register it through `SandboxManager::extend('your-provider', fn ($app, $cfg) => …)`. No workflow code changes.

The sample retry posture is intentional: provider activities set `$tries` higher than the framework default so transient failures (rate limits, network blips) do not drop tool calls. Permanent failures — quota exhausted, missing credentials, malformed template — are converted to `NonRetryableException` inside `ProvisionSandboxActivity` so the workflow surfaces a deterministic failure instead of looping. The `DestroySandboxActivity` swallows provider errors itself, because finalization is best-effort and `SandboxProvider::destroy()` is required to be idempotent.

Run the sample with:

```bash
php artisan app:sandbox                              # local subprocess provider
php artisan app:sandbox --snapshot-every=2           # snapshot every 2 tool calls
php artisan app:sandbox --suspend-between            # idle-suspend + resume between calls
php artisan app:sandbox --snapshot-every=2 --inject-loss-after=2  # force local restore
SANDBOX_DRIVER=e2b E2B_API_KEY=… php artisan app:sandbox
```

See [docs/sandbox-orchestration.md](docs/sandbox-orchestration.md) for the full pattern walkthrough, the file layout, and the procedure for adding a third provider.

#### Polyglot

The repository ships a runnable polyglot demonstration in
[`polyglot/`](polyglot/). It brings up the standalone Durable Workflow
server with real PHP workers (Laravel + Composer-installed
`durable-workflow/workflow`) and Python workers side by side. Three
scenarios run end to end:

- a Python-authored workflow on its own Python image, and
- a PHP-authored workflow (`App\Workflows\Polyglot\PhpToPythonWorkflow`)
  that schedules `polyglot.php-to-python.*` activities handled by the
  Python worker on a shared task queue, and
- a Python-authored workflow that schedules `polyglot.python-to-php.*`
  activities handled by a distinct PHP activity worker.

The two cross-language scenarios are the wire-level tests: the workflow
runtime and activity runtime register separately, and each scheduled
activity crosses the language boundary on the wire — not just inside
one process. The smoke runs in CI on every pull request via
`.github/workflows/polyglot-validation.yml`, so a regression in either
direction is caught before release rather than in the field.

The codec round-trip rules — which payload values cross the language
boundary cleanly and which need explicit adapters — are documented in
the workflow package at
[`docs/architecture/polyglot-codec-roundtrip.md`](https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/polyglot-codec-roundtrip.md).
Operators of polyglot fleets should treat the "requires an explicit
adapter" set as a workflow-author contract: the SDKs fail closed at the
boundary rather than guess at a serialisation.

#### Replay-Safety Teaching Notes

Durable Workflow v2 replays workflow code to rebuild local state from committed history. Keep workflow methods deterministic: call activities for side effects, use `sideEffect()` for values such as timestamps or random IDs, and wait for outside input through signals, updates, timers, or message streams.

Do this when a workflow needs the current time:

```php
use function Workflow\V2\sideEffect;

$startedAt = sideEffect(fn () => now()->getTimestamp());
```

Don't do this inside workflow code:

```php
$startedAt = now();
```

The direct `now()` call looks harmless, but replay can run the method again later and produce a different value than the one that originally drove branching, timeouts, or output. Prefer scalar values inside `sideEffect()` callbacks — integer timestamps, ISO-8601 strings, UUIDs — so the recorded value survives any configured payload codec on replay; returning a Carbon instance can decode as a plain string under non-JSON codecs such as Avro. The `ElapsedTimeWorkflow` sample keeps clock reads behind `sideEffect()` as integer timestamps, and the `SimpleWorkflow`, `PrismWorkflow`, and `AiWorkflow` samples keep external work inside activities for the same reason.

In addition to the basic example workflow, you can try these other workflows included in this sample app:

* `php artisan app:elapsed` – Demonstrates how to correctly track start and end times to measure execution duration.

* `php artisan app:microservice` – A fully working example of a workflow that spans multiple Laravel applications using a shared database and queue.

* `php artisan app:playwright` – Runs a Playwright automation against `https://example.com`, captures a WebM video, encodes it to MP4 using FFmpeg, and then cleans up the WebM file. Pass a URL to check another page, for example `php artisan app:playwright http://localhost:8000/waterline/dashboard`.

* `php artisan app:webhook` – Showcases how to use the built-in webhook system for triggering workflows externally.

* `php artisan app:prism` - Uses Prism to build a durable AI agent loop. It asks an LLM to generate user profiles and hobbies, validates the result, and retries until the data meets business rules.

* `php artisan app:ai` - NEW! Uses Laravel AI SDK to build a durable travel agent. The agent asks questions and books hotels, flights, and rental cars. If any errors occur, the workflow ensures all bookings are canceled. For repeatable checks, pass one or more `--message="..."` options and optionally `--inactivity-timeout=5`; use `--inject-failure=hotel`, `--inject-failure=flight`, or `--inject-failure=car` to exercise compensation. `--booking-plan-json='{"text":"...","bookings":[...]}'` lets deterministic scripted checks reuse a known booking plan while still exercising the workflow, booking activities, and compensation.

* `php artisan app:sandbox` - Durable sandbox orchestration sample. Provisions an ephemeral sandbox, dispatches a sequence of agent-decided tool calls through activities, snapshots the workspace at a configurable interval, recovers from sandbox loss by restoring the latest snapshot, and tears the sandbox down deterministically on every termination path. The default `local` provider runs subprocesses on the worker host; set `SANDBOX_DRIVER=e2b` plus `E2B_API_KEY` to run against the E2B Cloud sandbox API. Pass `--suspend-between` for suspend/resume, `--snapshot-every=2` for snapshots, or `--snapshot-every=2 --inject-loss-after=2` to force the documented local recovery path. See the [Sandbox Orchestration](#sandbox-orchestration) section below for the full pattern walkthrough.

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

If you prefer Docker, run `docker compose up --build`, then run `docker compose exec app php artisan migrate --force` once the containers are healthy. After migrations complete, connect to `http://localhost:8000/mcp/workflows`.

##### Available Tools

| Tool | Description |
|------|-------------|
| `list_workflows` | Discover configured workflow keys, credential requirements, status values, and recent v2 runs |
| `start_workflow` | Start a configured v2 workflow asynchronously and get a workflow instance ID plus run ID |
| `get_workflow_result` | Check workflow status, output, visibility metadata, and latest failure summary |
| `get_workflow_history` | Inspect a bounded slice of typed v2 history events and latest durable failures |
| `diagnose_workflow` | Summarize health facts, latest failure evidence, and safe next actions for stuck or failed runs |

##### Configuration

Available workflows are defined in `config/workflow_mcp.php`. By default, every workflow in the sample index is exposed:

- `simple` → `App\Workflows\Simple\SimpleWorkflow`
- `elapsed` → `App\Workflows\Elapsed\ElapsedTimeWorkflow`
- `microservice` → `App\Workflows\Microservice\MicroserviceWorkflow`
- `playwright` → `App\Workflows\Playwright\CheckConsoleErrorsWorkflow` (requires local Playwright/Node/FFmpeg setup)
- `webhook` → `App\Workflows\Webhooks\WebhookWorkflow` (waits for the `ready` signal)
- `prism` → `App\Workflows\Prism\PrismWorkflow` (requires `OPENAI_API_KEY`)
- `ai` → `App\Workflows\Ai\AiWorkflow` (requires `OPENAI_API_KEY`, then accepts `send` signals and `receive` updates)
- `sandbox` → `App\Workflows\Sandbox\SandboxAgentWorkflow` (provisions, dispatches tool calls, snapshots, recovers, and cleans up an ephemeral agent sandbox via `App\Sandbox\SandboxProvider`; defaults to the local subprocess provider, set `SANDBOX_DRIVER=e2b` plus `E2B_API_KEY` for E2B Cloud)
- `polyglot_php_to_python` → `App\Workflows\Polyglot\PhpToPythonWorkflow` (requires the `polyglot/` docker compose stack with the PHP and Python workers running; the stack smoke also exercises Python-authored workflows)

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
6. If status is `failed` or `waiting` longer than expected, call `diagnose_workflow`, then inspect `get_workflow_history` with the `run_id`
## Reporting Bugs and Requesting Samples

Use the structured templates under [Issues](https://github.com/durable-workflow/sample-app/issues/new/choose) so reproducers and sample requests land with the metadata maintainers need:

- **Bug reproducer.** A reproducer runs in this app: it names the workflow class, the artisan command, the Durable Workflow package version, and the observed durable failure. Reproducers that follow the template land as new workflows under `app/Workflows/Bug/<issue>/` and stay covered by CI after the bug is fixed.
- **Sample request.** A sample request names the Durable Workflow pattern that is not yet covered, the public docs page that defines it, and the minimum package version it needs. Requests close when a workflow under `app/Workflows/` exercises the pattern end to end and is wired into the artisan command list and `config/workflow_mcp.php`.

Bugs in the workflow engine itself or the standalone Durable Workflow server belong on the [`workflow`](https://github.com/durable-workflow/workflow/issues/new/choose) and [`server`](https://github.com/durable-workflow/server/issues/new/choose) repos respectively; the issue chooser links those out.

## Contributing a Sample

Have a Durable Workflow pattern you want to share? Read
[CONTRIBUTING.md](CONTRIBUTING.md) for the full contract — workflow
class layout, artisan command name, MCP entry, test, README index row,
and the docs-site gallery and pattern-page cross-link that ship in the
same change. The
[Contribute a Sample](https://durable-workflow.github.io/docs/2.0/contribute-a-sample)
page on the docs site is the canonical version of the same guide.

Maintainers tagging an upstream release should read
[`docs/release-notes-feature-contract.md`](docs/release-notes-feature-contract.md)
first; it names the bar a sample must meet to be cited in upstream
release notes and the checklist that runs before a release tag lands.

## Public Boundary Checks

This is a public repository. Do not add private tracker names, workspace-only absolute paths, or loop/lane metadata to files or new commit metadata. Run `scripts/check-public-boundary.sh` before publishing changes; CI runs the same scan on pushes and pull requests.
