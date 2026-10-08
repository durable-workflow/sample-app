# Published remote activity retry and recovery

This experiment extends the portable SDK audit with all five Rust-involving
workflow/activity directions: PHP → Rust, Python → Rust, Rust → PHP,
Rust → Python and Rust → Rust.

For each direction, exercise four scenarios: a retryable first-attempt failure,
worker SIGKILL during its first leased attempt, total deadline expiry across
both attempts and exhaustion after two retryable failures. Require the original
workflow run and activity execution, distinct attempt identities and the original
total deadline. Successful cases have one result and completion. Failed cases
have one terminal activity failure and one workflow failure carrying its actual
cause, with no third attempt or successful result. Submit obsolete claims through
the published SDK and require explicit refusal without changing durable history.

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
container with SIGKILL and starts a fresh container with a distinct worker
registration identity. This exercises recovery after the original attempt
deadline, rather than the immediate lease release provided when a worker
reregisters with its previous identity. The ordinary published
`activity:timeout-enforce` command scans real deadlines every second. No clock
or database record is edited.

The second callback waits while the original claim is submitted through the
published Rust SDK. Require HTTP 409 with a stale-claim reason, unchanged
history, a still-live second claim and unchanged deadlines. After releasing
the retry, verify its actual result and completion before the original total
deadline. Both attempts have a 20-second start-to-close budget. The whole
activity has one 120-second budget and two attempts with a two-second backoff.
Activities send no application heartbeats in this experiment.

Total deadline expiry uses a 30-second per-attempt budget and one original
30-second total budget. The retry starts later, so its attempt deadline is
after the original total deadline. Fail attempt one normally, then hold the live retry
until the original total deadline expires. Require `ActivityTimedOut` with
`schedule_to_close`, at or after the original deadline, and an unhandled
`WorkflowFailed`. Retry exhaustion uses the original two-attempt policy and
20/120-second budgets. Fail both callbacks and require the actual second
failure with `non_retryable: false`, one workflow failure and no third attempt.
The workflow failure must retain the terminal activity cause in either case.

Both failure cases first reject the obsolete first claim while attempt two is
live. After the run fails, submit the second claim and require HTTP 409,
unchanged terminal history, withdrawn attempt authority and the same total
deadline. The callback gates, real deadline scanner and ordinary SDK APIs
exercise these boundaries without editing database records or clocks.

The focused Action runs all twenty cases, checks the observer's rejection of
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
