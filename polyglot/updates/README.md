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

Rust update workers require SDK 3.3.0 or later. Their registration includes the
handler argument contracts and declared completion signal Server records when
starting a run.

Each call checks the named handler's result through the real SDK client and
persisted `UpdateAccepted`/`UpdateCompleted` history. It then kills the Rust
worker, accepts an update while that process is absent, starts its replacement,
and requires the same update/run identities and one completion. Repeating the
request returns that original completion. A failed Rust handler must persist a
failed update while leaving its workflow live. All three original workflows
finish once after a signal.

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
