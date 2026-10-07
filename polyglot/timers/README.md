# Published SDK durable timers

Run PHP, Python and Rust timer workflows against the published Server with:

```bash
scripts/playground doctor
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
SDK_TIMERS_COMPOSE_PROJECT_NAME=sample-app-sdk-timers scripts/sdk-timers.sh
```

Use the prepared Sample App development container with Docker Compose. The
checked-in artifact tuple selects exact published packages and images. The
experiment builds workers from those packages, starts a disposable MySQL/Redis
Server stack, and checks four scenarios for each workflow language:

- Normal completion after the original 30-second deadline, with exactly one
  scheduled timer, matching fire and completed result.
- Worker `SIGKILL` while waiting, timer fire while all SDK workers are absent,
  then cold replay in replacement processes without rescheduling or duplicate
  completion.
- Server and timer-queue restart across the deadline, preserving timer identity
  and completing once from the original history.
- Cooperative cancellation while waiting, duplicate requests preserving the
  original request identity and cleanup deadline, one delivered cancellation
  and cancelled timer, and no fire or completion after the timer's original
  due time has passed.

Every start also checks the public API's `waiting` status. The Python SDK is the
observer and control client. PHP, Python and Rust author and execute their own
timer workflows. Timers have no remote activity or cross-language timer-worker
direction to multiply into a workflow/activity matrix.

The timer runner enables each worker's cooperative cancellation capability and
protocol 1.20 with `POLYGLOT_TIMER_COOPERATIVE=1`. Ordinary polyglot commands keep
their existing capability selection. The long-running Rust workflow worker uses
`recover_transient_outages(true)` to remain alive during the Server
restart with capped retry backoff. Its startup and permanent errors still require
operator attention or a process supervisor.

The runner prints the exact tuple, Server digest, timestamps, per-language
results and persisted timer events. Record the command, Sample App commit,
UTC interval and twelve scenario outcomes in the owning GitHub issue. Its exit
trap removes the isolated Compose stack, volumes and task worker images on
success or failure. Shared published base images are preserved.
If interrupted externally, repeat the removal with the same project name:

```bash
COMPOSE_PROJECT_NAME=sample-app-sdk-timers COMPOSE_PROFILES=timers \
  docker compose -f polyglot/docker-compose.yml down --volumes --remove-orphans
```

This is focused SDK timer coverage. Concurrent distinct deadlines, nested
cancellation scopes and timer-bearing application upgrades have separate
qualification requirements.

## Single Rust restart example

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
