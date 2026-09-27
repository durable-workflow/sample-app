# Python-created schedule with a Rust worker

This focused service-mode experiment creates a fixed-interval schedule through
the published Python SDK and waits for an automatic fire into the published
Rust SDK workflow worker. It checks the Rust result, linked schedule audit
event, and durable workflow history. The Python client never calls the manual
trigger API. It covers one cross-language schedule direction, not cadence,
restart, or every Server schedule conformance scenario.

Run from the repository root inside the prepared Sample App development
container. Use an isolated Compose project and the checked-in published
artifact tuple:

```bash
scripts/playground doctor
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
export COMPOSE_PROJECT_NAME=sample-app-schedule-rust

docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  build smoke rust-workflow-worker
docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  up -d --wait mysql redis bootstrap server rust-workflow-worker scheduler
docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  run --rm --no-deps \
  -e DURABLE_WORKFLOW_RUNTIME_URL=http://server:8080 \
  -e DURABLE_WORKFLOW_CLIENT_TOKEN=test-token \
  -e DURABLE_WORKFLOW_TASK_QUEUE=polyglot-rust \
  smoke python /app/scripts/python_created_rust_schedule.py
```

The client prints the schedule ID, automatic fire count, linked workflow/run
IDs, Rust result, schedule audit events, and workflow event types. It deletes
its schedule after checking the first completed run. The `schedule-rust`
profile starts a two-second evaluator only for this experiment; other
polyglot runs do not need it.

Record the actual artifact versions, Server image digest, runner commit, UTC
start/finish, and checked result on the owning GitHub issue. A successful
worker registration alone is not schedule evidence. Stop and remove the
disposable stack after the run, including on failure:

```bash
docker compose -f polyglot/docker-compose.yml --profile schedule-rust \
  down --volumes --remove-orphans
```
