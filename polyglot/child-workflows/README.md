# Cross-language child workflows

## Published failure and recovery qualification

Run this command from the prepared Sample App development container:

```bash
scripts/playground doctor
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
SDK_CHILDREN_COMPOSE_PROJECT_NAME=sample-app-sdk-children scripts/sdk-children.sh
```

It builds the existing PHP, Python and Rust workers from the frozen published
tuple, starts an isolated Server/MySQL/Redis stack with its queue consumer and runs all nine successful
parent/child directions. For the five directions involving Rust, it also
requires a typed child failure matched to durable history and a cold recovery.
The child waits for a declared signal. All three workers are SIGKILLed, then the
signal is acknowledged while they are absent. Fresh worker processes must
complete the original parent and child runs with one schedule, start and
completion, without duplicating work. Results and failures must match the
persisted parent and child histories, including their original relationship.

Those five directions also use cooperative cancellation with
`WAIT_CANCELLATION_COMPLETED` and `RequestCancellation`. Each request gets one
30-second cleanup budget. The child records its cancellation context and starts
a shielded cleanup timer. All workers are killed during that timer. Duplicate
requests while workers are absent must return the original identity and
deadline. Replacement workers must replay the original delivery and timer,
finish child and parent cleanup, and close both original runs as Cancelled
before that deadline. The published CLI and Server API must explain the same
complete cascade with its original lineage and completed cleanup.

Original and duplicate requests from the published Python SDK pass through an
isolated attribution gateway. It preserves the real token and protocol headers
while injecting five forged identity body fields and eleven headers. Each
direction requires successful HTTP 202/200 receipts with the original request
identity and deadline. Root history must record the authenticated legacy token
actor, and the same requester and control-plane source must remain in both
cancellation contexts, cleanup markers and CLI/API cascade requests after replay.
Propagation, delivery and cleanup are internal events with explicit null audit
principals. They preserve the requester in the context. Worker inspection proves
SIGKILL exit 137 without OOM and distinct replacement containers.

The command repeats the five cancellation directions with a named runtime-token
operator and in explicitly configured anonymous mode. All three published SDKs
make original requests, selected by the parent language. Duplicate requests use
the child language. In token mode the duplicate uses the legacy administrator,
so it must preserve the original operator requester despite that change of
caller. Anonymous requests carry no Authorization header and must retain the
documented `server` / `anonymous` requester. Each batch repeats the physical
worker loss, cold cleanup replay and CLI/API cascade checks.

Before the operator requests, all three SDKs must reject a worker credential
with HTTP 403 `forbidden`, and a missing credential with HTTP 401 `unauthorized`.
The missing credential is refused during capability discovery, before a
cancellation POST. Actual gateway receipts and unchanged durable histories
are required for both refusals. Browser rendering is a separate case.

The command prints the tuple, digest, UTC interval, results and original
identities. Its exit trap removes the task stack, volumes and fixture images on
success or failure. After an external interruption, remove the same project:

```bash
export CHILD_UID="$(id -u)" CHILD_GID="$(id -g)"
# Use the disposable proof directory printed at startup.
export CHILD_PROOF_DIR=/path/to/disposable/proof
COMPOSE_PROJECT_NAME=sample-app-sdk-children docker compose \
  -f polyglot/docker-compose.yml -f polyglot/docker-compose.children.yml \
  down --volumes --remove-orphans
```

Set `SDK_CHILDREN_RESULT_DIR` to retain scenario histories, cascade output,
actual injected request receipts and physical worker-loss records. Hosted
checks retain these for 30 days. The disposable proof directory is removed by
the exit trap independently of that evidence retention.

Process loss during an external side effect requires a separate scenario.
Report the executed directions and exact tuple on the owning issue.

## Run the authoring examples manually

This example runs a parent and child in every PHP, Python, and Rust direction
against one isolated, published Server. It uses the Sample App's existing
Composer install, Python SDK in the prepared development image, and the Rust
lockfile in `polyglot/rust_worker`. It is a focused service-mode experiment, not
an embedded Laravel example or a replacement for the Server's broader child
workflow conformance runner.

Run these commands inside the prepared Sample App development environment from
the repository root. First start the local stack with a distinct Compose
project. The playground's own Rust workflow completes before returning; leave
the stack running for this experiment.

```sh
scripts/playground doctor
PLAYGROUND_COMPOSE_PROJECT=sample-app-child-matrix scripts/playground rust
```

Set the runtime URL printed by the playground. In Codespaces it normally uses
`host.docker.internal`; on a local host it normally uses `127.0.0.1`. The token
below belongs only to this disposable local stack. Do not aim this experiment
at a customer namespace.

```sh
export DURABLE_WORKFLOW_RUNTIME_URL=http://host.docker.internal:18082
export DURABLE_WORKFLOW_NAMESPACE=default
export DURABLE_WORKFLOW_TASK_QUEUE=sample-app-child-matrix
export DURABLE_WORKFLOW_WORKER_TOKEN=playground-token
export DURABLE_WORKFLOW_CLIENT_TOKEN=playground-token
```

With those variables available in each terminal, start the three workers:

```sh
php polyglot/child-workflows/php_worker.php
```

```sh
python polyglot/child-workflows/python_worker.py
```

```sh
cargo run --quiet --locked --manifest-path polyglot/rust_worker/Cargo.toml --bin child_matrix_worker
```

From a fourth terminal, run the matrix client:

```sh
python polyglot/child-workflows/client.py
```

It prints one result per parent/child direction and ends with `9/9
child-workflow directions completed`. Each result is checked for the requested
child language, unchanged input, and durable parent history containing one
child schedule, start, completion, and parent completion in order. A missing
worker or type-name mismatch fails the run instead of being counted as a pass.

Record the actual Server, Waterline, CLI, PHP, Python, and Rust versions from
the playground output and installed package/lockfiles with the run's UTC time
and nine results on the owning GitHub issue. A published artifact that merely
installed is not evidence of an executed direction. The manual client checks
successful child completion. The automated command above adds the five
Rust-involving typed failure, worker recovery and cooperative cancellation
cases. Saga compensation has a separate experiment.

Stop the workers, then remove only this local stack and its volumes:

```sh
PLAYGROUND_COMPOSE_PROJECT=sample-app-child-matrix scripts/playground down rust
```
