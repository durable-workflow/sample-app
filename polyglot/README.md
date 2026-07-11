# Polyglot Sample

This directory is the runnable polyglot demonstration that ships with the
sample app. It proves the Durable Workflow control plane is language-neutral
by running a conformance smoke around a complete three-language runtime matrix against one
standalone server, with real workers in different languages registered on
coordinated task queues. The smoke drives workflow start, signal, query, and
result retrieval through the published `dw` CLI and checks the same run through
Waterline.

The main sample app (`docker-compose.yml` at the repository root) is the
single-language, in-process Laravel demo. This directory is a **separate**
demonstration — its own `docker-compose.yml`, its own services, its own
smoke — so the simple Laravel-only path stays simple.

## What it exercises

Nine workflow/activity runtime cells run end to end:

| Scenario | Workflow language | Activity language | Source |
| --- | --- | --- | --- |
| Python authoring | Python (`sdk-python`) | Python | `python_workflow/workflow.py` |
| PHP authoring | PHP (`durable-workflow/workflow`) | PHP | `app/Workflows/Polyglot/PhpSameLanguageWorkflow.php` + `app/Console/Commands/PolyglotWorker.php` |
| Cross-language activity | PHP (`durable-workflow/workflow`) | Python | `app/Workflows/Polyglot/PhpToPythonWorkflow.php` + `python_worker/activities.py` |
| Reverse cross-language activity | Python (`sdk-python`) | PHP (`durable-workflow/workflow`) | `python_workflow/workflow.py` + `app/Console/Commands/PolyglotWorker.php` |
| Rust authoring | Rust (`sdk-rust`) | Rust | `rust_worker/src/main.rs` |
| Rust to Python | Rust (`sdk-rust`) | Python | `rust_worker/src/main.rs` + `python_worker/activities.py` |
| Rust to PHP | Rust (`sdk-rust`) | PHP (`durable-workflow/workflow`) | `rust_worker/src/main.rs` + `app/Console/Commands/PolyglotWorker.php` |
| Python to Rust | Python (`sdk-python`) | Rust (`sdk-rust`) | `python_workflow/workflow.py` + `rust_worker/src/main.rs` |
| PHP to Rust | PHP (`durable-workflow/workflow`) | Rust (`sdk-rust`) | `app/Workflows/Polyglot/PhpToRustWorkflow.php` + `rust_worker/src/main.rs` |

The PHP-authored scenario is the wire-level cross-language test:

- `php-workflow-worker` is a real Laravel + Composer-installed
  `durable-workflow/workflow` container that registers
  `polyglot.php-to-python.PhpToPythonWorkflow` on the
  `polyglot-php-to-python` task queue. Its workflow source is the same
  file the main sample app's MCP listing surfaces.
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
- `php-activity-worker` is a separate Laravel + Composer-installed
  `durable-workflow/workflow` container that registers
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
  booleans, nulls, mixed lists, nested maps, timestamps, and binary values
  represented by the published JSON-native codec as explicit base64 objects;
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
uses `apache/avro` from Packagist, Python uses `avro` from PyPI, and Rust uses
`apache-avro` from crates.io. Each echo activity reports its official package
and version, and the machine-readable output records input/output JSON types
and equality for every value in every direction.

The smoke emits a run metadata JSON document after all required surfaces run.
That document includes exact public artifact pins for the server image, CLI,
Python SDK, Rust SDK, PHP SDK, and Waterline, the Apache Avro dependency
versions, plus pass/fail status per surface.

The codec contract that determines which payload values cross the
language boundary cleanly is documented in the workflow package:
[Polyglot Codec Round-Trip Contract](https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/polyglot-codec-roundtrip.md).

## Layout

```
polyglot/
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
│       ├── php_to_python_smoke.py      PHP→Python smoke driver
│       └── python_to_php_smoke.py      Python→PHP smoke driver
├── rust_worker/
│   ├── Cargo.toml                      crates.io-only worker dependencies
│   ├── Cargo.lock                      reproducible dependency graph
│   ├── Dockerfile                      exact released SDK build
│   └── src/main.rs                     Rust workflows, activities, signal/query
├── php_worker/
│   └── Dockerfile                      Real PHP image (Laravel + durable-workflow PHP SDK)
└── README.md                           this file
```

The smoke driver scripts live under `python_worker/scripts/` because the
`smoke` service in `docker-compose.yml` reuses the `python_worker` image
build context, and the Dockerfile bakes the `scripts/` tree into the
image at `/app/scripts/`. Editing those files there is the only way to
change what the smoke service runs — there is no bind mount.

The PHP-authored polyglot workflow lives with the rest of the sample
app's PHP code at `app/Workflows/Polyglot/PhpToPythonWorkflow.php` so it
is autoloaded by Laravel and discoverable through the same MCP listing
machinery as the other samples. The polyglot PHP worker container
reuses the sample-app Laravel image and runs the
`php artisan app:polyglot-worker` command. In `--mode=workflow` it
registers itself against the standalone server and pumps workflow tasks
through a Fiber-based replay of the same class file. In
`--mode=activity` it registers PHP activities consumed by the
Python-authored workflow.

## Running locally

```bash
while IFS= read -r assignment; do export "$assignment"; done < <(scripts/resolve-current-artifacts.sh)
cd polyglot
docker compose up -d --build --wait \
  server python-activity-worker php-same-workflow-worker php-same-activity-worker \
  php-workflow-worker php-to-rust-workflow-worker php-query-worker php-activity-worker \
  python-workflow-worker rust-workflow-worker rust-activity-worker waterline
docker compose run --rm --build smoke
docker compose down -v
```

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
runs the same `docker compose run --rm --build smoke` on every push and
pull request, so the checked-out smoke driver scripts are rebuilt into
the image before they execute. A regression in either direction is
caught here, not in the field.

## Codec round-trip notes

All scenarios use the `avro` codec by default — that is the v2
default. Values that round-trip cleanly (JSON-native scalars, lists,
maps) flow without adapter code; values that need explicit codec
negotiation (PHP `BackedEnum`, Python `dataclasses`, `Decimal`,
`datetime`) are listed in the codec round-trip contract linked above.
The smoke fixtures stay inside the clean round-trip set by design so
the demo runs without per-language adapter shims.

## Waterline rendering

The polyglot compose stack starts a Waterline service against the same
standalone server database. The smoke reads Waterline's JSON endpoints for
same-language and mixed-language runs and verifies that workflow arguments,
outputs, event typing, and worker attribution render with the same fidelity
across runtime combinations. Waterline reads each row's `payload_codec` column
rather than sniffing blob shape, so a run authored in Python decodes to the
same JSON structure a PHP run does.
