# PHP- and Python-created schedules with a Rust worker

These focused service-mode experiments create fixed-interval schedules through
the published PHP or Python SDK and wait for automatic fires into the
published Rust SDK workflow worker. Each checks the Rust result, linked
schedule audit event, and durable workflow history. Neither client calls the
manual trigger API. They cover two cross-language schedule directions, not
cadence, restart, or every Server schedule conformance scenario.

Run from the repository root inside the prepared Sample App development
container. Use an isolated Compose project and the checked-in published
artifact tuple:

```bash
scripts/playground doctor
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
export COMPOSE_PROJECT_NAME=sample-app-schedule-rust

docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  build php-same-workflow-worker smoke rust-workflow-worker
docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  up -d --wait mysql redis bootstrap server rust-workflow-worker scheduler
docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  run --rm --no-deps \
  -e DURABLE_WORKFLOW_RUNTIME_URL=http://server:8080 \
  -e DURABLE_WORKFLOW_CLIENT_TOKEN=test-token \
  -e DURABLE_WORKFLOW_TASK_QUEUE=polyglot-rust \
  smoke python /app/scripts/python_created_rust_schedule.py
docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  run --rm --no-deps \
  -e POLYGLOT_RUST_TASK_QUEUE=polyglot-rust \
  php-same-workflow-worker php /app/php_created_rust_schedule.php
```

Each client prints the schedule ID, automatic fire count, linked workflow/run
IDs, Rust result, schedule audit events, and workflow event types. It deletes
its schedule after checking the first completed run. The `schedule-rust`
profile starts a two-second evaluator only for these experiments; other
polyglot runs do not need it.

Record the actual artifact versions, Server image digest, runner commit, UTC
start/finish, and checked result on the owning GitHub issue. A successful
worker registration alone is not schedule evidence. Stop and remove the
disposable stack after the run, including on failure:

```bash
docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  down --volumes --remove-orphans
```
