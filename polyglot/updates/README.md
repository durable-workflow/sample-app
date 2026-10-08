# Published SDK workflow updates

Run all nine PHP/Python/Rust client/update-handler directions from the prepared
Sample App development container:

```bash
scripts/playground doctor
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
SDK_UPDATES_COMPOSE_PROJECT_NAME=sample-app-sdk-updates scripts/sdk-updates.sh
```

The checked-in tuple pins published Server, PHP/Python SDK packages and Rust
crate versions. The command builds the existing workers, starts an isolated
MySQL/Redis stack, and exercises each of the three client languages against
each of the three handler languages. These are client-to-handler directions.
The Server's existing update experiment separately covers its embedded probe,
PHP process boundary, Python SDK surface fixtures, validators and diagnostics.

The Rust worker's registration includes handler argument contracts and declared
signals that Server records when starting a run.

Each call checks the named handler's result through the real SDK client and
persisted `UpdateAccepted`/`UpdateCompleted` history. It then kills all three
workers, accepts an update while those processes are absent, starts replacements,
and requires the same update/run identities and one completion. Repeating the
request returns that original completion. A failed Rust handler must persist a
failed update while leaving its workflow live. All three original workflows
finish once after a signal.

The Python client must raise `UpdateFailed` with the handler's message and
matching workflow, run, update and failure IDs. Repeating that failed request
must return the same error identities with one durable failed completion.

A Rust query and update inspect the same original workflow input and committed
signals before and after worker replacement, including a map argument, one nested
array argument and no arguments. The update result must also match
its persisted completion. This exercises the immutable state snapshot that a
stateful handler uses to reconstruct its input and prior signal deliveries.
The Rust workflow consumes these three signals before waiting for completion.

All nine client/handler directions also increment workflow state. Each original
run accumulates three changes and records their request identities in order.
Queries must return this accumulated value without adding workflow history.
After all workers are killed, another increment and its duplicate are admitted
for each run. Fresh workers must recover the prior state, apply that increment
once and return the original accepted identity. Repeating the earlier completed
updates through all three clients returns their original results, while queries
retain the latest state. Each final workflow result must contain that same state.

Python uses its bound update and query methods. PHP reconstructs state through
the documented committed-history context. Rust reconstructs a typed workflow
instance and queries its detached replayed state. These authoring surfaces share
the same durable outcome.

Rust does not support synchronous pre-accept update validators. The installed
crate must return `UnsupportedUpdateValidators` for a contract claiming one.
This check does not claim validator execution, process loss during an external
side effect, or coverage of every update ordering/race condition.

The command prints the exact tuple, Server digest, UTC interval, SDK results,
run/update identities and relevant durable events. Report the scenario outcomes
on the owning issue. Its exit trap removes the task stack, volumes and worker
images on success or failure. If externally interrupted, clean up with the same
project name:

```bash
COMPOSE_PROJECT_NAME=sample-app-sdk-updates \
  docker compose -f polyglot/docker-compose.yml down --volumes --remove-orphans
```
