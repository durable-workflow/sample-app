# Polyglot Service Mode

This directory contains the Sample App's standalone Server topology and its
PHP, Python, and Rust workers. The primary example is `PolyglotWorkflow`: a PHP
workflow that calls a Python activity and then a Rust activity.

## Run the example

From the repository root:

```bash
scripts/polyglot.sh
```

The command:

1. reads the stable artifact versions in `qualified-artifact-tuple.json`;
2. starts MySQL, Redis, and the published Durable Workflow Server image;
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
| `python_workflow/` | Python-authored workflow examples |
| `laravel/` | Waterline image used to inspect standalone runs |
| `docker-compose.yml` | Complete service-mode topology |
| `qualified-artifact-tuple.json` | Stable package and image versions used by the examples |

## Full runtime matrix

The Compose topology also contains same-language and cross-language workers
used by the broader smoke driver. After exporting the stable tuple, run it in
an isolated project:

```bash
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
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

SAMPLE_APP_RUST_SDK_VERSION=2.0.1 scripts/polyglot.sh
```

Overrides must be stable 2.x versions. The resolver prints every effective
artifact before Compose starts.
