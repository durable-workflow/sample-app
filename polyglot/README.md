# Polyglot Sample

This directory contains the primary service-mode `PolyglotWorkflow` and the
complete PHP/Python/Rust validation stack. It also holds a narrower Laravel
integration variation. Every scenario runs against a standalone Durable
Workflow Server and uses published 2.0 artifacts.

For a first Rust run in Durable Workflow Cloud controlled early access rather
than this exhaustive self-hosted matrix, use the dedicated
[Rust Cloud quickstart](https://durable-workflow.com/docs/2.0/polyglot/rust-cloud-quickstart/)
and the repository's `scripts/rust-cloud.sh run` entry point. That path uses one
Rust workflow/activity pair, separate client and worker credentials, the
Cloud-aware CLI, a development build, and Managed Waterline verification.

## Service mode: PolyglotWorkflow

From a Sample App Codespace after setup reports ready, run:

```bash
scripts/polyglot.sh
```

The PHP-authored `polyglot.PolyglotWorkflow` runs on `polyglot-workflow`. It
routes an order-total activity to the Python worker on
`polyglot-php-to-python`, then passes that result to the Rust receipt activity
on `polyglot-to-rust`. The Server dispatches both activity tasks; neither
runtime is simulated by PHP. The final result names all three runtimes and
queues and combines the Python total with the Rust receipt.

The command resolves the current installable artifact tuple, builds the three
workers through their normal package managers, waits for their exact handler
registrations, and runs the workflow. Docker, Composer, Python, and Rust are
already present in the prepared Codespaces image, so there is no manual setup
or hidden host command.

### Laravel integration variation

From a Sample App Codespace, run:

```bash
scripts/service-mode.sh
```

The command uses `polyglot/service-mode.yml` and the prepared development image;
there is no local image build. Laravel runs the framework-neutral
`durable-workflow/sdk` bridge, resolves attributed handlers through its service
container, writes worker lifecycle events to its normal logger, and starts a
workflow through an injected `WorkflowClientInterface`. That workflow executes
`sample.service-mode.php.prepare` in the Laravel worker and
`sample.service-mode.python.decorate` in a Python worker before returning one
combined result.

The output names what started, prints the PHP and Python results, and links to
the matching run in Waterline. Startup, result, and browser-check timings plus a
browser screenshot are retained under `storage/app/`. The isolated Compose
volumes remain available for inspection, and generated workflow IDs make repeat
runs safe.

## Complete runtime matrix

The full `docker-compose.yml` validation first runs `PolyglotWorkflow`, then
proves the control plane is language-neutral across nine directional PHP,
Python, and Rust workflow/activity cells. It drives workflow start, signal,
query, result retrieval, replay, and codec checks through the published `dw`
CLI and inspects the same runs through Waterline.

The root app's embedded Laravel path, the service-mode examples, and the full
matrix each use separate Compose projects and state.

## What it exercises

Nine workflow/activity runtime cells run end to end:

| Scenario | Workflow language | Activity language | Source |
| --- | --- | --- | --- |
| Python authoring | Python (`sdk-python`) | Python | `python_workflow/workflow.py` |
| PHP authoring | PHP (`durable-workflow/sdk`) | PHP | `php_worker/worker.php` |
| Cross-language activity | PHP (`durable-workflow/sdk`) | Python | `php_worker/worker.php` + `python_worker/activities.py` |
| Reverse cross-language activity | Python (`sdk-python`) | PHP (`durable-workflow/sdk`) | `python_workflow/workflow.py` + `php_worker/worker.php` |
| Rust authoring | Rust (`sdk-rust`) | Rust | `rust_worker/src/main.rs` |
| Rust to Python | Rust (`sdk-rust`) | Python | `rust_worker/src/main.rs` + `python_worker/activities.py` |
| Rust to PHP | Rust (`sdk-rust`) | PHP (`durable-workflow/sdk`) | `rust_worker/src/main.rs` + `php_worker/worker.php` |
| Python to Rust | Python (`sdk-python`) | Rust (`sdk-rust`) | `python_workflow/workflow.py` + `rust_worker/src/main.rs` |
| PHP to Rust | PHP (`durable-workflow/sdk`) | Rust (`sdk-rust`) | `php_worker/worker.php` + `rust_worker/src/main.rs` |

The PHP-to-Python matrix cell remains a focused directional conformance test:

- `php-workflow-worker` is a framework-neutral Composer project that installs
  the exact published `durable-workflow/sdk` release and registers
  `polyglot.php-to-python.greeter` on the
  `polyglot-php-to-python` task queue. Its image contains neither Laravel nor
  the embedded `durable-workflow/workflow` engine.
- `python-activity-worker` is a Python container that registers
  `polyglot.php-to-python.reverse` and `polyglot.php-to-python.tally`
  on the same task queue.
- `php-query-worker` is a PHP query-only worker on the same queue. It
  answers server-routed `state` queries for the PHP signal/query workflow
  while the workflow worker is parked in a pull-style signal wait.
- Each run schedules a real activity dispatch — workflow code is in
  PHP, activity code is in Python — so the Avro envelope crosses the
  language boundary on the wire, not just inside one process.

The Python-authored same-language scenario is the language-symmetric
reference:

- `python-workflow-worker` is a long-running Python `durable-workflow`
  worker that registers the `polyglot.python.greeter` workflow plus its
  `polyglot.python.greet` and `polyglot.python.summarise` activities on
  the `polyglot-python` task queue.
- The smoke driver acts purely as a client: it waits for the Python
  worker to register, starts a workflow, and asserts the result. The
  workflow itself executes inside the running container, so the
  docker-compose stack is the actual unit under test.

The PHP-authored same-language scenario is the PHP reference:

- `php-same-workflow-worker` registers `polyglot.php.greeter` on the
  `polyglot-php` task queue.
- `php-same-activity-worker` registers `polyglot.php.marker` and
  `polyglot.php.describe` on the same task queue.
- The smoke asserts that workflow and activity tasks are both handled by
  PHP workers through the standalone worker-plane protocol.

The Python-to-PHP scenario is the reverse wire-level cross-language
test:

- The same `python-workflow-worker` registers
  `polyglot.python-to-php.greeter` on the `polyglot-python` task queue.
- `php-activity-worker` is a separate process from the same published
  `durable-workflow/sdk` image that registers
  `polyglot.python-to-php.marker` and `polyglot.python-to-php.describe`
  on the `polyglot-python-to-php` task queue.
- The smoke asserts that the Python workflow result includes the PHP
  runtime marker returned by those activities.

The smoke also exercises the conformance surfaces around the original cells
and the five Rust cells:

- workflow start and result retrieval through the published `dw` CLI;
- signal and query handling through the published `dw` CLI for PHP-authored,
  Python-authored, and Rust-authored workflows;
- six-direction type round-trips for strings with non-ASCII text, ints, floats,
  booleans, nulls, mixed lists, nested maps, timestamps, and native binary
  values kept distinct from UTF-8 text at every worker boundary;
- typed activity error round-trips from Python activity to PHP workflow and PHP
  activity to Python workflow;
- Waterline event typing, payload rendering, and worker attribution for
  same-language and mixed-language runs.

The Rust image resolves the exact current `durable-workflow` release from
crates.io and contains no path or Git dependency. Its workflow worker executes
Rust-authored same-language and outbound PHP/Python paths; its activity worker
executes inbound PHP/Python paths. The harness verifies the advertised SDK
version before accepting a cell, so a version pin without an executed Rust
worker cannot pass.

All six cross-language type directions use the platform Avro envelope. PHP
uses `apache/avro` from Packagist, Python uses `fastavro` from PyPI, and Rust uses
`apache-avro` from crates.io. Each echo activity reports its official package
and version. For the binary case, each workflow constructs a native SDK value,
the activity validates and echoes that value, and the workflow validates the
echo before returning JSON-safe byte-equality evidence to the smoke driver.

The smoke emits a run metadata JSON document after all required surfaces run.
That document includes separate exact public artifact pins and roles for the
server image, CLI, framework-neutral PHP SDK, Python SDK, Rust SDK, embedded
Laravel Workflow engine, and Waterline. It also records the Apache Avro
dependency versions and pass/fail status per surface.

The codec contract that determines which payload values cross the
language boundary cleanly is documented in the workflow package:
[Polyglot Codec Round-Trip Contract](https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/polyglot-codec-roundtrip.md).

## Layout

```
polyglot/
├── service-mode.yml                   no-build Laravel + Python quickstart stack
├── service_mode/
│   └── python_worker.py               quickstart cross-language activity
├── docker-compose.yml                  full stack (server + workers + smoke)
├── python_workflow/
│   ├── workflow.py                     Python-authored workflow + activities
│   └── Dockerfile                      Python image
├── python_worker/
│   ├── activities.py                   Python activities consumed by the PHP workflow
│   ├── Dockerfile                      Python image (also baked-in smoke driver)
│   └── scripts/
│       ├── smoke.sh                    shell entrypoint for the full smoke
│       ├── polyglot_smoke.py           drives all scenarios and emits metadata
│       ├── php_same_language_smoke.py  PHP-authoring sanity driver
│       ├── python_workflow_smoke.py    Python-authoring smoke driver
│       ├── polyglot_workflow_smoke.py  featured PHP→Python→Rust driver
│       └── python_to_php_smoke.py      Python→PHP smoke driver
├── rust_worker/
│   ├── Cargo.toml                      crates.io-only worker dependencies
│   ├── Cargo.lock                      reproducible dependency graph
│   ├── Dockerfile                      exact released SDK build
│   └── src/main.rs                     Rust workflows, activities, signal/query
├── php_worker/
│   ├── composer.json                   framework-neutral Composer project
│   ├── Dockerfile                      published durable-workflow/sdk image
│   └── worker.php                      PHP workflows, activities, signal/query
├── laravel/
│   └── Dockerfile                      embedded Workflow + Waterline host
└── README.md                           this file
```

The smoke driver scripts live under `python_worker/scripts/` because the
`smoke` service in `docker-compose.yml` reuses the `python_worker` image
build context, and the Dockerfile bakes the `scripts/` tree into the
image at `/app/scripts/`. Editing those files there is the only way to
change what the smoke service runs — there is no bind mount.

The standalone PHP implementation lives entirely in `php_worker/worker.php`
and uses `DurableWorkflow\Client`, `DurableWorkflow\Worker`, workflow and query
contexts, and the SDK's Apache Avro codec. The matching classes under
`app/Workflows/Polyglot/` remain Laravel teaching material for the root
embedded sample and its MCP catalog; they are not copied into the standalone
worker image. Waterline is likewise hosted in the separate `laravel` image,
where `durable-workflow/workflow` retains its actual role as the embedded
Laravel engine.

Every PHP, Python, and Rust worker uses the same fixed recursive
`durable_workflow.protocol.Value` schema with Avro single-object framing.
Smoke evidence verifies the `c301` marker, schema fingerprint, and native type
matrix at each language boundary; JSON is exercised only when explicitly
selected as the fallback codec.

## Running locally

Run the featured workflow with the same one-command path used in Codespaces:

```bash
scripts/polyglot.sh
```

For the complete conformance matrix, use an isolated project name:

```bash
while IFS= read -r assignment; do export "$assignment"; done < <(scripts/resolve-current-artifacts.sh)
export COMPOSE_PROJECT_NAME="sample-app-polyglot-local-${USER:-user}"
POLYGLOT_BUILD_CACHE_MODE=cold-cache scripts/polyglot-validation.sh
```

Use `POLYGLOT_BUILD_CACHE_MODE=warm-cache` to prime the image graph and then
exercise the cached build path. The validation script builds the complete
artifact topology before starting it, brings up Server, every worker, and
Waterline together, and runs registration probes plus smoke without allowing
either one-off container to start or recreate dependencies. It retains bounded
Compose diagnostics on failure and removes the isolated project on exit.

The `smoke` service runs `/app/scripts/smoke.sh` (baked in from
`python_worker/scripts/smoke.sh`), which:

1. waits for the Python, PHP, and Rust workers to register on their coordinated
   task queues;
2. uses `dw workflow:start --wait --json` to run every cell in the PHP,
   Python, and Rust workflow/activity matrix;
3. uses `dw workflow:start`, `dw workflow:query`, `dw workflow:signal`, and
   `dw workflow:describe` to verify signal/query parity through the published
   CLI for Python-authored, PHP-authored, and Rust-authored workflows;
4. runs the six-direction type round-trip and typed-error matrices through the
   same published CLI entrypoint;
5. reads Waterline JSON endpoints for the mixed-language and same-language
   runs and compares event typing, payload rendering, and worker attribution;
6. emits one machine-readable conformance metadata document with artifact pins
   and pass/fail status for every required surface.

The final stdout block is the run metadata document. It records the public
artifact pins used by the run and the surface matrix for the CLI, runtime,
codec, typed-error, signal/query, and Waterline checks. Every required surface
must pass before the smoke exits successfully.

Removing `php-workflow-worker` from the `up` line is the regression
test for "this stack is actually polyglot": the smoke fails fast with
"no PHP worker registered on task queue" instead of silently passing.
Removing `php-activity-worker` fails the matching Python-to-PHP check
with "no php worker registered on task queue".
Removing `python-workflow-worker` fails the matching "no Python worker
registered on task queue" check on the symmetric side.
Removing either Rust worker fails the corresponding runtime-registration check;
the report cannot mark the Rust SDK exercised from tuple metadata alone.

## CI

The `.github/workflows/polyglot-validation.yml` GitHub Actions job
runs the lifecycle harness in cold-cache and warm-cache cells on every push and
pull request. Each cell resolves the public artifact tuple once, builds the
checked-out smoke driver before startup, and verifies that worker readiness and
smoke execution stay bound to the Server container created by the initial
topology bootstrap. A regression in either direction is caught here, not in the
field.

## Codec round-trip notes

All scenarios use the `avro` codec by default — that is the v2
default. Native scalars, lists, maps, bytes, and UTF-8 strings flow through
the fixed Value schema; values that need explicit codec
negotiation (PHP `BackedEnum`, Python `dataclasses`, `Decimal`,
`datetime`) are listed in the codec round-trip contract linked above.
PHP uses the SDK's `AvroBinaryValue` adapter to distinguish byte strings from
text, while Python uses `bytes` and Rust uses `AvroValue::Bytes`.

## Waterline rendering

The polyglot compose stack starts a Waterline service against the same
standalone server database. The smoke reads Waterline's JSON endpoints for
same-language and mixed-language runs and verifies that workflow arguments,
outputs, event typing, and worker attribution render with the same fidelity
across runtime combinations. Waterline reads each row's `payload_codec` column
rather than sniffing blob shape, so a run authored in Python decodes to the
same JSON structure a PHP run does.
