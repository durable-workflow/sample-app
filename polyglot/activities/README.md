# Published remote activity retry and recovery

This experiment runs every PHP, Python and Rust workflow/activity direction.
Each workflow language calls an activity in each of the three languages,
including its own language, for nine directions.

For each direction, exercise five scenarios: a retryable first-attempt failure,
worker SIGKILL during its first leased attempt, total deadline expiry across
both attempts, exhaustion after two retryable failures and application progress
heartbeat expiry. Require the original
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
stack and workers needed here. The Python activity worker has one CPU for
supervised callback startup. The other SDK workers have half a CPU, and each
SDK worker has a 256 MiB memory
limit.

The first callback publishes its real claim and waits at a fixture gate. The
observer records the live claim and original
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
The four retry, worker loss, total deadline and exhaustion cases send no
application heartbeats.

## Application progress and heartbeat timeout

This case explicitly enables the published cooperative worker mode and
protocol 1.20 before starting its activity. PHP uses
`enableCooperativeCancellation: true`, Python advertises
`cooperative_cancellation`, and Rust uses `.cooperative_cancellation(true)`.
These workers observe attempt authority independently of application
heartbeats and stop the expired callback. Releasing the retry's final gate
must not let the expired callback resume application code. The first four scenarios retain
their ordinary worker mode. The runner changes modes between cases, then
requires no container restart during the actual heartbeat-expiry case.

The progress case uses actual `ActivityContext.heartbeat()` calls in each SDK.
Its first attempt records five ordered progress updates three seconds apart,
including integer steps, a fractional value, a boolean, null and Unicode text.
The same attempt remains live beyond the first ten-second heartbeat deadline.
Read-only ownership checks expose the latest accepted heartbeat and its renewed
deadline while preserving the original 60-second attempt and 120-second total
budgets.

The callback then stops sending progress while its worker container continues
running. The ordinary deadline scanner must schedule retry for `heartbeat`,
after the last accepted heartbeat deadline and before the attempt deadline.
The next attempt uses the same worker registration and original run/execution,
with a new attempt identity and the original total deadline. Its application
continues reporting progress until released to complete.

An obsolete first claim submits both a completion and another heartbeat
through the published Rust SDK. Neither may alter the expired attempt's five
progress records or accepted history. Only real progress from the live retry
may be appended during these requests. The worker container identity, process
and start time remain unchanged across heartbeat expiry and retry. Results
retain the actual progress history, deadline snapshots and refusal responses.

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

The focused Action runs all forty-five cases, rejects omitted or duplicate
directions, checks the observer's rejection of
contradictory attempts and retains thin JSON observations and logs for 30 days.
The same worker registration must take the following exhaustion case after
deadline expiry. Results include run/execution/attempt IDs, installed SDK versions, deadlines,
histories and physical container failure/replacement records. Record its exact
runner commit, artifact tuple, Server digest and UTC interval in the owning
issue. The exit trap removes task containers, networks, volumes and built
consumer images on success or failure. Retain useful result files separately
and remove disposable local state at the task handoff. If externally interrupted,
remove the same project with both Compose files and its original proof directory.

This checks durable results and claim fencing. Exactly-once external effects
require application idempotency or downstream fencing and have separate cases.

## External effect committed before activity worker loss

Run the separate nine-direction suite with the same published tuple:

```bash
SDK_ACTIVITY_RECOVERY_COMPOSE_PROJECT_NAME=sample-app-external-effects \
  scripts/sdk-activity-recovery.sh --external-effects --result-dir /tmp/external-effects-result
```

Each real PHP, Python or Rust activity first commits a synthetic operation to a
separate HTTP service, then waits before returning its SDK result. The service
uses a SQLite transaction and a unique application operation key, supplied in
the immutable workflow input. A repeated key with the same input returns the
original effect identity. Changed input is refused. This application contract
supplies idempotency independently of Workflow's attempt fencing.

The observer verifies that the effect is committed while the original workflow
and activity remain pending. The runner then SIGKILLs the actual activity worker
and starts a distinct replacement. After the original attempt deadline and
recorded backoff, the second SDK callback repeats the same logical operation.
Require the original effect identity, byte-equivalent canonical input and one
business effect, with two downstream delivery receipts tied to the actual
activity attempts. The original total activity deadline remains authoritative.

Before releasing the retry, submit the obsolete first claim through the
published Rust SDK and require refusal without changed history. After normal
SDK completion, require one original workflow completion, the retry's actual
result and an unchanged downstream ledger. Results retain the committed effect,
both delivery receipts, claim/deadline snapshots and physical SIGKILL records.
The isolated service and its synthetic database are removed with the task.

The focused external-effect Action also tests concurrent duplicate HTTP requests,
conflicting inputs and physical downstream SIGKILL/restart over the same SQLite
database. No real customer account or external provider is involved. The normal
45-case recovery suite remains separate, and this fixture makes no guarantee
about services that lack idempotency or their own fencing contract.
