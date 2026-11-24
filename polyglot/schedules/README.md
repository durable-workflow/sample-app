# PHP- and Python-created schedules across SDKs

These focused service-mode experiments create fixed-interval schedules through
the published PHP and Python SDKs and wait for automatic fires into authored
PHP, Python, and Rust workflow workers. Each of the six directions checks the
workflow and activity result, a schedule audit event linked to the original
run, and the durable workflow history. The clients use automatic evaluation.
Cadence, restart, overlap, and backfill need separate scenarios. The Rust SDK
supports the workflow worker role in this matrix.

Run from the repository root inside the prepared Sample App development
container. Use an isolated Compose project and the checked-in published
artifact tuple:

```bash
scripts/playground doctor
scripts/prepare-apt-build-source.sh
(
  set -euo pipefail
  assignments=$(scripts/resolve-current-artifacts.sh)
  while IFS= read -r assignment; do export "$assignment"; done <<< "$assignments"
  export COMPOSE_PROJECT_NAME="sample-app-schedules-${BASHPID}"
  compose=(docker compose -f polyglot/docker-compose.yml)
  trap '"${compose[@]}" down --volumes --remove-orphans' EXIT

  "${compose[@]}" build php-same-workflow-worker python-workflow-worker rust-workflow-worker smoke
  "${compose[@]}" up -d --wait mysql server \
    php-same-workflow-worker php-same-activity-worker python-workflow-worker rust-workflow-worker
  for runtime in php python rust; do
    "${compose[@]}" run -T --rm --no-deps \
      -e DURABLE_WORKFLOW_RUNTIME_URL=http://server:8080 \
      -e DURABLE_WORKFLOW_CLIENT_TOKEN=test-token \
      -e DURABLE_WORKFLOW_TASK_QUEUE=polyglot-rust \
      smoke python /app/scripts/python_created_rust_schedule.py "$runtime"
    "${compose[@]}" run -T --rm --no-deps \
      -e POLYGLOT_PY_TASK_QUEUE=polyglot-python \
      -e POLYGLOT_RUST_TASK_QUEUE=polyglot-rust \
      php-same-workflow-worker php /app/php_created_rust_schedule.php "$runtime"
  done
)
```

Each client prints the schedule ID, automatic fire count, linked workflow/run
IDs, authored result, schedule audit events, and workflow event types. It deletes
its schedule after checking the first completed run. Native Server
evaluates schedules in its own process, so no separate evaluator runs.

Record the actual artifact versions, Server image digest, runner commit, UTC
start/finish, and outcome for each creator and worker pair on the
[conformance audit](https://github.com/durable-workflow/.github/issues/122).
The command removes the disposable stack and volumes on exit.
