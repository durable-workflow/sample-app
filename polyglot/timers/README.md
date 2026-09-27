# Rust durable timer across a worker restart

This focused service-mode experiment runs a timer authored with the published
Rust SDK against a published Server image. The Python SDK client observes one
pending `TimerScheduled` event, stops the Rust worker, lets the Server queue
worker fire the timer, then restarts the Rust worker. Verification requires one
matching `TimerFired` event during the stopped window, no early fire, and one
completed Rust result after cold replay. It covers this one direction and
restart; it does not cover cancellation, Server restart, or PHP/Python SDK
timer workflows.

Run from the repository root inside the prepared Sample App development
container. Use a disposable Compose project and the checked-in published
artifact tuple:

```bash
scripts/playground doctor
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
export COMPOSE_FILE=polyglot/docker-compose.yml
export COMPOSE_PROFILES=timer-rust
export COMPOSE_PROJECT_NAME=sample-app-rust-timer
export DURABLE_WORKFLOW_TIMER_ID="rust-timer-$(date -u +%Y%m%d%H%M%S)"

docker compose build smoke rust-workflow-worker
docker compose up -d --wait mysql redis bootstrap server timer-queue rust-workflow-worker
docker compose run --rm --no-deps \
  -e DURABLE_WORKFLOW_RUNTIME_URL=http://server:8080 \
  -e DURABLE_WORKFLOW_CLIENT_TOKEN=test-token \
  -e DURABLE_WORKFLOW_TASK_QUEUE=polyglot-rust \
  -e DURABLE_WORKFLOW_TIMER_ID \
  smoke python /app/scripts/rust_timer_restart.py start

docker compose stop rust-workflow-worker
export DURABLE_WORKFLOW_WORKER_STOPPED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
sleep 32
export DURABLE_WORKFLOW_WORKER_RESTART_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
docker compose start rust-workflow-worker
docker compose run --rm --no-deps \
  -e DURABLE_WORKFLOW_RUNTIME_URL=http://server:8080 \
  -e DURABLE_WORKFLOW_CLIENT_TOKEN=test-token \
  -e DURABLE_WORKFLOW_TIMER_ID \
  -e DURABLE_WORKFLOW_WORKER_STOPPED_AT \
  -e DURABLE_WORKFLOW_WORKER_RESTART_AT \
  smoke python /app/scripts/rust_timer_restart.py verify
```

The `timer-rust` profile starts the Server's queue worker only for this
experiment. Record the exact Server digest, SDK versions, Sample App commit,
UTC start/finish, stopped/restart timestamps, result and history on the owning
GitHub issue. Stop and remove the disposable stack after the run, including
on failure:

```bash
docker compose down --volumes --remove-orphans
```
