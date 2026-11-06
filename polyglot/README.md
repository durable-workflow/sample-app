# Polyglot Service Mode

This directory contains the Sample App's standalone Server topology and its
PHP, Python, and Rust workers. The primary example is `PolyglotWorkflow`: a PHP
workflow that calls a Python activity and then a Rust activity.

## Run the example

From the repository root:

```bash
scripts/polyglot.sh
```

The script prepares and verifies the pinned public central build source. Before
invoking a Compose build directly, run `scripts/prepare-apt-build-source.sh` once.

The command:

1. reads the stable artifact versions in `qualified-artifact-tuple.json`;
2. starts MySQL and the published native Durable Workflow Server 3.x image,
   which creates its schema and `default` namespace itself;
3. builds one worker image for each first-party SDK;
4. waits for the required workflow and activity registrations;
5. starts `polyglot.PolyglotWorkflow`; and
6. prints the completed result and the exact runtime versions.

The workflow uses these queues:

| Runtime | Handler | Task queue |
| --- | --- | --- |
| PHP | `polyglot.PolyglotWorkflow` | `polyglot-workflow` |
| Python | order calculation activity | `polyglot-php-to-python` |
| Rust | receipt activity | `polyglot-to-rust` |

The stack remains available after the run. Stop it with:

```bash
docker compose \
  --project-directory polyglot \
  -f polyglot/docker-compose.yml \
  -p sample-app-polyglot-demo \
  down --volumes --remove-orphans
```

Set `POLYGLOT_COMPOSE_PROJECT_NAME` before running the script when you need a
different isolated project name.

## Layout

| Path | Purpose |
| --- | --- |
| `php_worker/` | PHP SDK workflow and activity worker |
| `python_worker/` | Python SDK workers and runtime checks |
| `rust_worker/` | Rust SDK workflow and activity worker |
| [`child-workflows/`](child-workflows/README.md) | Runnable PHP/Python/Rust parent-child matrix using the local playground runtime |
| [`sagas/`](sagas/README.md) | Five Rust-involving workflow/compensation directions across PHP, Python, and Rust |
| [`schedules/`](schedules/README.md) | PHP- and Python-created automatic schedules dispatched to a Rust workflow worker |
| [`timers/`](timers/README.md) | PHP/Python/Rust timer completion, worker SIGKILL and cold replay, Server restart and cooperative cancellation |
| [`updates/`](updates/README.md) | Nine PHP/Python/Rust SDK client/update-handler directions, Rust replacement and duplicate requests |
| [`namespaces/`](namespaces/README.md) | Published Rust namespace-bound clients/workers, role credentials, denied cross-namespace operations and cold recovery |
| [`activities/`](activities/README.md) | All nine PHP/Python/Rust SDK directions with retry, activity worker SIGKILL, original deadlines, application progress heartbeats, stale claim refusal and downstream idempotency |
| `python_workflow/` | Python-authored workflow examples |
| `laravel/` | Waterline host that inspects standalone runs through the Server API |
| `docker-compose.yml` | Complete service-mode topology |
| `qualified-artifact-tuple.json` | Stable package and image versions used by the examples |

## Full runtime matrix

The Compose topology also contains same-language and cross-language workers
used by the broader smoke driver. After exporting the stable tuple, run it in
an isolated project:

```bash
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
scripts/prepare-apt-build-source.sh
export COMPOSE_PROJECT_NAME="sample-app-polyglot-matrix-${USER:-user}"

docker compose \
  --project-directory polyglot \
  -f polyglot/docker-compose.yml \
  up --detach --build --wait

docker compose \
  --project-directory polyglot \
  -f polyglot/docker-compose.yml \
  run --rm --no-deps smoke
```

The matrix covers PHP, Python, and Rust workflow/activity directions, portable
Avro values, failures, replay, signals, queries, CLI control, and Waterline
rendering. It is a development diagnostic; the concise `scripts/polyglot.sh`
journey is the supported first-run path.

## Artifact overrides

The checked-in tuple keeps the sample reproducible. To test another stable
build, point the resolver at another tuple or override one version explicitly:

```bash
DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE=/path/to/tuple.json \
  scripts/polyglot.sh
```

Set `SAMPLE_APP_RUST_SDK_VERSION` to override the Rust crate (stable 2.x, 3.x
or 4.x). Set `SAMPLE_APP_SERVER_VERSION` or `SAMPLE_APP_SERVER_IMAGE` to select
another Server image; only native 3.x images are accepted, because the Compose
topology has no PHP bootstrap job, queue worker or scheduler. The resolver
prints every effective artifact before Compose starts.

To move the Rust SDK pin, run `scripts/pin-rust-sdk.sh <version>`. It updates
the tuple, both Cargo manifests and lock files, and the README together.
