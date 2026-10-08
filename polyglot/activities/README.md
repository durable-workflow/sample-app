# Published remote activity retry and recovery

This experiment extends the portable SDK audit with all five Rust-involving
workflow/activity directions: PHP → Rust, Python → Rust, Rust → PHP,
Rust → Python and Rust → Rust.

For each direction, exercise a retryable first-attempt failure and a worker
SIGKILL during the first leased attempt. Require the original workflow run and
activity execution, a distinct second attempt, the original total deadline,
one activity result and one workflow completion. Submit the old claim through
the published SDK and require explicit stale completion refusal without
changing durable history.

Run from the repository root in the prepared development container:

```bash
scripts/playground doctor
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
SDK_ACTIVITY_RECOVERY_COMPOSE_PROJECT_NAME=sample-app-activity-recovery \
  scripts/sdk-activity-recovery.sh --result-dir /tmp/activity-recovery-result
```

The checked-in tuple selects published packages and Server. The command builds
the existing PHP, Python and Rust consumer images and starts only the isolated
stack and workers needed here. The first callback publishes its real claim and
waits at a fixture gate. The observer records the live claim and original
deadlines through the SDK's read-only ownership API. Retry releases that gate
and injects an ordinary callback failure. Worker loss kills the actual activity
container with SIGKILL and starts a fresh container. The ordinary published
`activity:timeout-enforce` command scans real deadlines every second. No clock
or database record is edited.

The second callback waits while the original claim is submitted through the
published Rust SDK. Require HTTP 409 with a stale-claim reason, unchanged
history, a still-live second claim and unchanged deadlines. After releasing
the retry, verify its actual result and completion before the original total
deadline. Both attempts have a 20-second start-to-close budget. The whole
activity has one 120-second budget and two attempts with a two-second backoff.
Activities send no application heartbeats in this experiment.

The focused Action runs all ten cases, checks the observer's rejection of
contradictory attempts and retains thin JSON observations and logs for 30 days.
Results include run/execution/attempt IDs, installed SDK versions, deadlines,
histories and physical container failure/replacement records. Record its exact
runner commit, artifact tuple, Server digest and UTC interval in the owning
issue. The exit trap removes task containers, networks, volumes and built
consumer images on success or failure. Retain useful result files separately
and remove disposable local state at the task handoff. If externally interrupted,
remove the same project with both Compose files and its original proof directory.

This checks durable results and claim fencing. Exactly-once external effects
require application idempotency or downstream fencing and have separate cases.
