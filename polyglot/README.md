# Polyglot Sample

This directory is the runnable polyglot demonstration that ships with the
sample app. It proves the Durable Workflow control plane is language-neutral
by running three scenarios end to end against one standalone server, with
real workers in different languages registered on coordinated task queues.

The main sample app (`docker-compose.yml` at the repository root) is the
single-language, in-process Laravel demo. This directory is a **separate**
demonstration — its own `docker-compose.yml`, its own services, its own
smoke — so the simple Laravel-only path stays simple.

## What it exercises

Three scenarios run end to end:

| Scenario | Workflow language | Activity language | Source |
| --- | --- | --- | --- |
| Python authoring | Python (`sdk-python`) | Python | `python_workflow/workflow.py` |
| Cross-language activity | PHP (`durable-workflow/workflow`) | Python | `app/Workflows/Polyglot/PhpToPythonWorkflow.php` + `python_worker/activities.py` |
| Reverse cross-language activity | Python (`sdk-python`) | PHP (`durable-workflow/workflow`) | `python_workflow/workflow.py` + `app/Console/Commands/PolyglotWorker.php` |

The PHP-authored scenario is the wire-level cross-language test:

- `php-workflow-worker` is a real Laravel + Composer-installed
  `durable-workflow/workflow` container that registers
  `polyglot.php-to-python.PhpToPythonWorkflow` on the
  `polyglot-php-to-python` task queue. Its workflow source is the same
  file the main sample app's MCP listing surfaces.
- `python-activity-worker` is a Python container that registers
  `polyglot.php-to-python.reverse` and `polyglot.php-to-python.tally`
  on the same task queue.
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

The smoke emits a run metadata JSON document after all scenarios pass.
That document includes exact public artifact pins for the server image,
Python SDK, and PHP SDK, marks `dw` CLI and Waterline as not exercised
by this compose stack, and lists the scenario coverage matrix.

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
│       ├── python_workflow_smoke.py    Python-authoring smoke driver
│       ├── php_to_python_smoke.py      PHP→Python smoke driver
│       └── python_to_php_smoke.py      Python→PHP smoke driver
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
cd polyglot
docker compose up -d --build --wait \
  server python-activity-worker php-workflow-worker php-activity-worker python-workflow-worker
docker compose run --rm smoke
docker compose down -v
```

The `smoke` service runs `/app/scripts/smoke.sh` (baked in from
`python_worker/scripts/smoke.sh`), which:

1. waits for the Python runtime to register on `polyglot-python`, then
   drives the Python-authored workflow on `python-workflow-worker` and
   asserts the workflow result;
2. waits for the PHP runtime to register on `polyglot-php-to-python`,
   then drives the PHP-authored workflow on `php-workflow-worker` and
   asserts that activities executed by the Python worker round-trip
   cleanly back into the PHP workflow output;
3. waits for the PHP runtime to register on `polyglot-python-to-php`,
   then drives the Python-authored workflow that schedules PHP
   activities and asserts the PHP runtime marker in the output.

The final stdout block is the run metadata document. It records the
public artifact pins used by the run and the coverage matrix, including
the explicit `not_exercised` status for the `dw` CLI and Waterline
observer surfaces.

Removing `php-workflow-worker` from the `up` line is the regression
test for "this stack is actually polyglot": the smoke fails fast with
"no PHP worker registered on task queue" instead of silently passing.
Removing `php-activity-worker` fails the matching Python-to-PHP check
with "no php worker registered on task queue".
Removing `python-workflow-worker` fails the matching "no Python worker
registered on task queue" check on the symmetric side.

## CI

The `.github/workflows/polyglot-validation.yml` GitHub Actions job
runs the same `docker compose run --rm smoke` on every push and pull
request. A regression in either direction is caught here, not in the
field.

## Codec round-trip notes

All scenarios use the `avro` codec by default — that is the v2
default. Values that round-trip cleanly (JSON-native scalars, lists,
maps) flow without adapter code; values that need explicit codec
negotiation (PHP `BackedEnum`, Python `dataclasses`, `Decimal`,
`datetime`) are listed in the codec round-trip contract linked above.
The smoke fixtures stay inside the clean round-trip set by design so
the demo runs without per-language adapter shims.

## Waterline rendering

When the standalone server is paired with Waterline (not configured in
this directory because Waterline ships with the main sample app), the
polyglot run renders in Waterline with the same fidelity as a
single-language run. Waterline reads each row's `payload_codec` column
rather than sniffing blob shape, so a run authored in Python decodes
to the same JSON structure a PHP run does.
