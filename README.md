# Durable Workflow Sample App

This Laravel 13 application has two first-class ways to run Durable Workflow
2.0: an embedded Laravel engine and a standalone service with PHP and Python
workers. Both paths run in a GitHub Codespace on the supported 2.0 prerelease
train. Stable Durable Workflow 2.0 has not been released yet.

> **Looking for the Laravel 12 / Durable Workflow 1.x version?** It's preserved on the [`Laravel-12` branch](https://github.com/durable-workflow/sample-app/tree/Laravel-12). Older blog posts and tutorials that reference v1 patterns (e.g. `Workflow\Workflow`, `yield activity(...)`, `Workflow\Activity`) target that branch.

## Start in Codespaces

Create a Codespace from the main branch of this repository.

<img src="https://user-images.githubusercontent.com/1130888/233664377-f300ad50-5436-4bb8-b172-c52e12047264.png" alt="image" width="300">

Wait while Codespaces pulls the prepared Sample App development image and
installs the repository's Composer and npm dependencies. PHP, Node, Composer,
Chromium, and operating-system packages are already in the image, so they are
not rebuilt for each Codespace.

When setup finishes, choose either runnable path:

| Path | Best fit | Runtime |
| --- | --- | --- |
| [Embedded Laravel](#embedded-laravel) | A Laravel application that owns workflow execution and storage | Laravel app + queue worker |
| [Service mode](#service-mode) | A Laravel application calling a standalone Server with language-neutral workers | Server + Laravel SDK worker + Python SDK worker |

### Embedded Laravel

Codespaces setup has already created the environment, generated the application
key, migrated the database, and verified MySQL, Redis, and Playwright. Start
Laravel's web, queue, log, and asset processes:

```bash
composer run dev
```

In a second terminal, start the example workflow:

```bash
php artisan app:workflow
```

Open Waterline at the forwarded port 18080 URL under `/waterline`. The workflow
result also appears in the terminal. Run the workflow and activity tests with:

```bash
php artisan test
```

The embedded stack uses the Codespace's `sample-app` Compose state. Workflow
instances receive generated identities, so the command is safe to run again.

### Service mode

From the same Codespace checkout, run one command:

```bash
scripts/service-mode.sh
```

The command resolves the current supported 2.0 artifact tuple, pulls the
published Server and prepared runtime images, and starts an isolated
`sample-app-service-mode` Compose project without building an SDK or runtime
image. The Laravel SDK bridge resolves its workflow and PHP activity through
the service container, sends worker diagnostics to Laravel's logger, and then
routes a second activity to the Python worker.

The terminal reports the generated workflow ID, both activity results, startup,
result, and browser-check timing, and a Waterline URL for that exact run. It also
retains a browser screenshot beside the timing record. The stack remains
available at forwarded port 18081 for inspection. Run the command again at any
time; each journey uses a new workflow ID and does not share database or Redis
state with embedded mode.

The implementation is intentionally application-shaped:

- `config/durable-workflow.php` configures the Laravel bridge and handler services;
- `app/Workflows/ServiceMode/` contains the attributed workflow and injected PHP activity;
- `app:service-mode` injects `WorkflowClientInterface`, waits for the result, and supports the SDK test fake;
- `polyglot/service_mode/python_worker.py` handles the cross-language activity.

For the full PHP/Python/Rust runtime matrix, replay fixtures, signals, queries,
and codec checks, continue to the [`polyglot/` guide](polyglot/README.md).

## Observe a run

Check the two observability surfaces separately:

| Surface | Use it for | Where to look |
|---------|------------|---------------|
| Waterline and the workflow database | Durable workflow truth: run status, typed history, signals, updates, timers, retries, failures, and operator actions. | The exact run URL printed by either path, or `/waterline` |
| Worker logs and SDK metrics | Runtime behavior: poll latency, task duration, exporter wiring, custom application metrics, and worker-side errors before they become durable failures. | Laravel logs for PHP workers; SDK metrics endpoints for external workers |

Waterline proves that the durable run exists and shows what the engine or
standalone Server committed. Worker metrics remain a separate runtime surface.
Minimal Python worker Prometheus wiring uses the moving 2.0 prerelease
constraint rather than a copied release-candidate number:

```bash
python -m pip install --pre 'durable-workflow[prometheus]~=2.0'
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

## Prebuilt development image

The default devcontainer Compose file pulls
`ghcr.io/durable-workflow/sample-app-devcontainer:main`. The same image is
available from Docker Hub as
`durableworkflow/sample-app-devcontainer:main`; select it without changing the
Compose file by setting `SAMPLE_APP_DEVCONTAINER_IMAGE` before opening the
devcontainer. Both channels support Linux AMD64 and ARM64.

`main` is the moving channel built from protected `main` pushes and the weekly
refresh. The Chromium revision follows the exact Playwright version in
`package-lock.json`, which is covered by the weekly dependency update path.
Every publication is retained under an immutable
`sha-<source-revision>-run-<workflow-run>-<attempt>` tag in both registries.
Published indexes include OCI source/revision labels, BuildKit provenance, and
an SPDX SBOM. The moving tags advance only after both registry copies and both
architectures pass the unauthenticated Compose qualification.

The prepared image also runs the OpenSSH server expected by Codespaces tooling,
so remote shell and creation-log access do not require a per-Codespace feature
install.

There is deliberately no local Dockerfile fallback in the Codespaces Compose
topology. A pull or qualification failure stops setup instead of reconstructing
the old operating-system environment. To qualify a published image manually
and write phase timings to a JSON file, run:

```bash
scripts/ci/qualify-devcontainer-image.sh \
  ghcr.io/durable-workflow/sample-app-devcontainer:main \
  linux/amd64 \
  devcontainer-qualification-timing.json
```

The qualification pulls the selected image, starts the same MySQL/Redis and
Laravel/microservice topology with `--no-build`, installs only repository
dependencies, launches Chromium as the non-root `laravel` user, verifies the
SSH endpoint, checks the application health endpoint and mounted-checkout
editability, and records fresh and warm startup timings.

----

## Run Locally With Docker

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

For a release-style proof from a clean checkout, use the combined entry point
instead of the manual build, migration, and sample commands above. It builds the
resolved artifact tuple once, runs deterministic smoke, and continues through
the provider-free conformance matrix on the same healthy stack and schema. AI
surfaces are recorded as intentional skips, so this command needs no provider
credential:

```bash
scripts/compose-smoke-conformance.sh
```

The standalone full-conformance entry point remains self-contained for callers
that do not need the deterministic preflight. AI-backed surfaces are disabled
by default, so this form cannot consume a provider credential discovered in the
shell or an ancestor dotenv file:

```bash
scripts/compose-conformance.sh
```

The harness emits a JSON document with the sample-app commit, artifact versions,
timestamp, per-surface outcome, focused findings, setup measurements, and any
skipped surfaces. Setup measurements include whether the run started with a
clean or warm image cache, setup duration, peak Docker disk growth, build
invocation count, and whether a prepared stack was reused. Run the combined
entry point once without its app image and again with the resulting cache to
capture comparable clean-cache and warm-cache measurements. It runs the documented
artisan samples, browser checks for the app and Waterline, the MCP workflow API,
an API documentation check that compares the README's documented MCP tools and
workflow keys with the live endpoint, a Waterline/manual observation check using
`workflow:v2:history-export`, local sandbox lifecycle variants, sandbox recovery
injection, and, in explicit provider mode, the Prism/AI samples. The Prism check
uses `OPENAI_API_KEY` for the live model-backed AI surface. The travel-agent success and failure-injection
checks reuse one deterministic booking plan so the run proves signals, durable
assistant messages, booking activities, and compensation without spending extra
model calls on each failure variant.
The polyglot harness builds the exact released Rust SDK from crates.io and
executes Rust-authored workflows and activities across the PHP, Python, and
Rust runtime matrix. Its report distinguishes registered Rust execution from
the release-cohort version pin.
`SAMPLE_APP_CONFORMANCE_SKIP_AI=1` is the safe release and automation mode. It
passes `--skip-ai` to `app:conformance`, keeps `OPENAI_API_KEY` out of Compose
exec arguments, and records every AI-backed surface as explicitly skipped while
the deterministic and scripted agent-operability surfaces still run. Intentional
AI skips are allowed automatically in this mode; combining them with `--strict`
is rejected as a contradictory coverage request.

Provider-backed conformance requires an explicit opt-in, even when a credential
is already present. Its release proof is:

```bash
export OPENAI_API_KEY=your-provider-key
SAMPLE_APP_CONFORMANCE_SKIP_AI=0 \
scripts/compose-smoke-conformance.sh --strict
```

Set `SAMPLE_APP_CONFORMANCE_ENV_FILE` only on that opt-in path when the key lives
in a dotenv file outside the repository; the wrapper then checks local
workspace-level dotenv files without printing credential values. Provider mode
requires strict coverage by default, and `--strict` makes that requirement
explicit. Without AI credentials, the run stays non-passing and names the live
Prism surface as uncovered. Set
`DURABLE_SERVER_IMAGE`, `DURABLE_WORKFLOW_CLI_VERSION`,
`DURABLE_WORKFLOW_PYTHON_SDK_VERSION`, `DURABLE_WORKFLOW_RUST_SDK_VERSION`,
`DURABLE_WORKFLOW_PHP_SDK_VERSION`, `DURABLE_WORKFLOW_WORKFLOW_VERSION`, and
`DURABLE_WORKFLOW_WATERLINE_VERSION` to override the published artifact set.
The PHP SDK variable selects the framework-neutral `durable-workflow/sdk`
package used by `polyglot/`; the Workflow variable selects the separate
`durable-workflow/workflow` engine used by this Laravel application.
By default, the wrapper calls
`scripts/resolve-current-artifacts.sh`, which resolves one 2.0 prerelease
channel from the public docs release-audit manifest. Beta tuples remain
synchronized, while release-candidate tuples may contain component-specific
increments as long as every component stays in the `rc` channel.

<!-- durable-workflow-artifact-channel-policy:start -->
| Prerelease channel | Component-version policy |
|--------------------|--------------------------|
| `beta` | `synchronized` |
| `rc` | `component-specific` |
| `mixed` | `rejected` |
<!-- durable-workflow-artifact-channel-policy:end -->

The resolver emits the accepted tuple as shell assignments and preserves
explicit overrides. The wrapper rebuilds the app and worker containers with the
resolved PHP SDK, Workflow, and Waterline pins before running the harness, so
the recorded versions come from installed packages rather than the committed
fallback lock. The polyglot Rust image likewise applies the resolved SDK version
to its build-local manifest, leaving the committed Cargo manifest and lock as
the pinned fallback. The standalone PHP stack independently installs and
executes the resolved PHP SDK pin. Set
`DURABLE_WORKFLOW_ARTIFACT_SOURCE=pinned` for a reproducible run against the
committed sample-app fallback tuple instead. Set
`DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE=/path/to/tuple.json` when a local run
should use a previously captured public tuple manifest.
The wrapper passes the host checkout SHA into the app container as
`SAMPLE_APP_COMMIT`; set that variable explicitly when running from a source
archive or another environment without Git metadata. The same value is forwarded
as a Docker build and runtime variable so source-free containers can report the
sample-app revision without reading a local `.git` checkout.
The wrapper also copies the JSON metadata back to
`storage/app/sample-app-conformance-metadata.json`; set
`SAMPLE_APP_CONFORMANCE_METADATA_PATH` to choose a different host-side path.
Pass that file as `DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH` when validating
the agent-operability executable-loop contract against the current artifact
tuple.
The app service has the browser-safe `sample-app` network alias, and the wrapper
uses `http://sample-app:8000` inside the Compose network so browser activities
running in the worker container can reach the app without an HTTPS upgrade. Set
`SAMPLE_APP_CONFORMANCE_URL` when running against a different network address.
The wrapper derives one coverage policy from the AI mode: provider-free mode
allows its intentional AI skips, while provider mode is strict by default. Set
`SAMPLE_APP_CONFORMANCE_ALLOW_SKIPS=1` only for exploratory provider-mode runs
that should return zero while naming missing provider-backed evidence.
`scripts/compose-smoke.sh` starts with the bounded deterministic preflight: it
runs the deterministic samples and exits after printing the blocked step,
container status, and recent app/worker logs on failure. By default, a passing
preflight continues into the broader public sample-app conformance surface so a
release/conformance caller does not accidentally record deterministic smoke as
full coverage. The handoff records the prepared app and worker containers; the
full wrapper reuses them only when their health, artifact tuple, credentials,
installed packages, and migrated schema still match. Otherwise it falls back to
its self-contained rebuild and schema reset. Set `SAMPLE_APP_SMOKE_ONLY=1` when
a caller intentionally wants only the deterministic path. Set
`SAMPLE_APP_CONFORMANCE_AFTER_SMOKE=0` to disable the chained full surface for
exploratory local runs, or run `scripts/compose-conformance.sh --strict`
directly with `SAMPLE_APP_CONFORMANCE_SKIP_AI=0` when a strict provider run does
not need the deterministic preflight.

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
| Orchestrate an ephemeral agent sandbox with durable lifecycle | `DurableWorkflow\AI\Workflows\SandboxAgentWorkflow` | `php artisan app:sandbox` | `sandbox` |
| Run the polyglot conformance smoke (complete PHP/Python/Rust runtime matrix) | PHP-authored workflows plus the Python and Rust workers in `polyglot/` | `while IFS= read -r assignment; do export "$assignment"; done < <(scripts/resolve-current-artifacts.sh); docker compose -f polyglot/docker-compose.yml run --rm smoke` | `polyglot_php_to_python` |
| Exercise machine-readable failure diagnosis and repair refusal | `App\Workflows\Diagnostics\DiagnosticFailureWorkflow` | `/mcp/workflows` `start_workflow` with `workflow=diagnostic_failure` | `diagnostic_failure` |

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

Long-running coding agents need an ephemeral workspace, but lifecycle and
recovery infrastructure should not be copied into each application. This app
consumes `durable-workflow/ai`; the package owns the versioned provider contract,
activities, `DurableWorkflow\AI\Workflows\SandboxAgentWorkflow`, E2B and local
adapters, stable operation IDs, post-snapshot reconstruction, leases, and
cleanup. The Sample App retains only its command, configuration example, and
end-to-end demonstration.

`config/durable-workflow-ai.php` selects the provider. The default local
subprocess provider is development/test-only, runs with the worker's privileges,
and is not a security isolation boundary. The E2B adapter uses the documented
HTTP API. This sample does not expose E2B suspend/resume because paused
sandboxes have no provider TTL; it must not be enabled without an independent
durable cleanup deadline. Both built-in providers explicitly declare
at-least-once tool effects; a lost acknowledgement can repeat a mutating call.

Run the sample with:

```bash
php artisan app:sandbox                              # local subprocess provider
php artisan app:sandbox --snapshot-every=2           # snapshot every 2 tool calls
php artisan app:sandbox --snapshot-every=2 --inject-loss-after=2  # force local restore
DURABLE_AI_SANDBOX_DRIVER=e2b E2B_API_KEY=… php artisan app:sandbox
```

See [docs/sandbox-orchestration.md](docs/sandbox-orchestration.md) for the
integration walkthrough and links to the package's delivery contract and
provider-author guide.

#### Polyglot

The repository ships a runnable polyglot demonstration in
[`polyglot/`](polyglot/). It brings up the standalone Durable Workflow
server with framework-neutral PHP workers from the published
`durable-workflow/sdk` package, Python workers, and crates.io-installed Rust
workers side by side. The root Laravel example remains a separate embedded
mode backed by `durable-workflow/workflow`. Nine workflow/activity runtime
cells run end to end:

- a Python-authored workflow on its own Python image, and
- a PHP-authored workflow in `polyglot/php_worker/worker.php`
  that schedules `polyglot.php-to-python.*` activities handled by the
  Python worker on a shared task queue, and
- a Python-authored workflow that schedules `polyglot.python-to-php.*`
  activities handled by a distinct PHP activity worker.
- Rust-authored workflows and activities run same-language, Rust-to-PHP,
  Rust-to-Python, PHP-to-Rust, and Python-to-Rust cells.

The cross-language scenarios are wire-level tests: the workflow
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

* `php artisan app:ai` - NEW! Uses Laravel AI SDK to build a durable travel agent. The agent asks questions and books hotels, flights, and rental cars. If a booking error occurs, the workflow ensures prior bookings are canceled; an inactivity timeout closes the conversation without rolling back successful interactive bookings. For repeatable checks, pass one or more `--message="..."` options and optionally `--inactivity-timeout=5`; use `--inject-failure=hotel`, `--inject-failure=flight`, or `--inject-failure=car` to exercise compensation. `--booking-plan-json='{"text":"...","bookings":[...]}'` lets deterministic scripted checks run a single planned turn while still exercising the workflow, booking activities, and compensation.

* `php artisan app:sandbox` - Package integration demo for `durable-workflow/ai`. The command dispatches a short tool sequence through the reusable sandbox workflow. Use `--snapshot-every=2 --inject-loss-after=2` to exercise local recovery, or set `DURABLE_AI_SANDBOX_DRIVER=e2b` plus `E2B_API_KEY` to use E2B Cloud. The local subprocess provider is development/test-only and is not a security isolation boundary; E2B suspend/resume is unavailable until paused resources have an independent durable cleanup deadline.

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
| `diagnose_workflow` | Summarize health facts, root-cause classification, remediation, latest failure evidence, and safe next actions for stuck or failed runs |
| `repair_workflow` | Request the built-in v2 repair command and receive a structured accepted, refused, or not-needed mutation result |

##### Configuration

Available workflows are defined in `config/workflow_mcp.php`. By default, every workflow in the sample index is exposed:

- `simple` → `App\Workflows\Simple\SimpleWorkflow`
- `elapsed` → `App\Workflows\Elapsed\ElapsedTimeWorkflow`
- `microservice` → `App\Workflows\Microservice\MicroserviceWorkflow`
- `playwright` → `App\Workflows\Playwright\CheckConsoleErrorsWorkflow` (requires local Playwright/Node/FFmpeg setup)
- `webhook` → `App\Workflows\Webhooks\WebhookWorkflow` (waits for the `ready` signal)
- `prism` → `App\Workflows\Prism\PrismWorkflow` (requires `OPENAI_API_KEY`)
- `ai` → `App\Workflows\Ai\AiWorkflow` (requires `OPENAI_API_KEY`, then accepts `send` signals and `receive` updates)
- `sandbox` → `DurableWorkflow\AI\Workflows\SandboxAgentWorkflow` (package-owned lifecycle and recovery; defaults to the development-only local subprocess provider, set `DURABLE_AI_SANDBOX_DRIVER=e2b` plus `E2B_API_KEY` for E2B Cloud)
- `polyglot_php_to_python` → `App\Workflows\Polyglot\PhpToPythonWorkflow` (requires the current artifact tuple resolver and the `polyglot/` docker compose stack with the PHP and Python workers running; the stack smoke also exercises Python-authored workflows)
- `diagnostic_failure` → `App\Workflows\Diagnostics\DiagnosticFailureWorkflow` (no credentials; intentionally records a durable activity failure so MCP clients can prove `diagnose_workflow` and `repair_workflow` behavior)

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
6. If status is `failed` or `waiting` longer than expected, call `diagnose_workflow`
7. Read `root_cause.category`, `remediation.classification`, and `remediation.automatic_repair.allowed`
8. Call `repair_workflow` only when remediation marks repair as allowed, then poll `get_workflow_result` and inspect `get_workflow_history` with the `run_id`
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
