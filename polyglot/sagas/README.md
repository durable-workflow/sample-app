# Cross-SDK saga compensation and restart

This experiment runs Rust, PHP and Python workflows that reserve two steps,
encounter an intentional activity failure, then compensate in reverse order.
Each workflow runtime runs compensation in PHP, Python, and Rust. The nine
directions check the completed result, persisted activity order, and outputs
from both compensation activities. The planned reservations and compensation
use fixture activities. The published Server saga runner has separate
PHP/Python scenarios.

Run inside the prepared Sample App development environment. Use only a
disposable local Server, never a customer namespace. The first playground
workflow finishes before returning and leaves the local stack up.

```sh
scripts/playground doctor
PLAYGROUND_COMPOSE_PROJECT=sample-app-saga scripts/playground rust
```

Set the runtime URL printed by the playground. In Codespaces this normally
uses `host.docker.internal`; on a local host it normally uses `127.0.0.1`.
The token below belongs only to the disposable stack. Set these values in
each terminal:

```sh
export DURABLE_WORKFLOW_RUNTIME_URL=http://host.docker.internal:18082
export DURABLE_WORKFLOW_NAMESPACE=default
export DURABLE_WORKFLOW_TASK_QUEUE=sample-app-saga
export DURABLE_WORKFLOW_WORKER_TOKEN=playground-token
export DURABLE_WORKFLOW_CLIENT_TOKEN=playground-token
```

Start each worker in a separate terminal:

```sh
cargo run --quiet --locked --manifest-path polyglot/rust_worker/Cargo.toml --bin saga_compensation_worker
```

```sh
php polyglot/sagas/php_worker.php
```

```sh
python polyglot/sagas/python_worker.py
```

Then run the client from a fourth terminal:

```sh
python polyglot/sagas/client.py
```

The client reports each completed direction and ends with
`9/9 saga compensation directions completed`. A missing handler, mismatched
activity result, absent planned failure, or out-of-order compensation fails the
run.

With the same disposable stack and workers, exercise a terminal compensation
failure in all nine directions:

```sh
python polyglot/sagas/client.py --compensation-failure
```

This input makes `undo-second` fail on its first attempt. The client requires
the typed `SagaCompensationFailed` terminal event, both recorded activity
failures, and no scheduled `undo-first`. The ordinary client command above
still checks that both compensations complete in reverse order.

To check cold replay before compensation, leave the disposable stack and all
three workers up. Choose each combination of `php`, `python`, and `rust`
for the workflow and compensation flags. Restart the worker for the workflow
language in each pair. The commands below show PHP with Python compensation.

For each row, start one workflow with its flags:

```sh
python polyglot/sagas/restart_client.py start \
  --workflow-runtime php --compensation-runtime python
```

After it prints `restart_boundary`, stop the selected parent worker and wait
for that process to exit. While it is stopped, deliver the signal using the
printed ID and the same flags:

```sh
python polyglot/sagas/restart_client.py signal <workflow-id> \
  --workflow-runtime php --compensation-runtime python
```

Start a new process for that parent worker, keeping the other workers running,
then verify the resumed run with the same flags:

```sh
python polyglot/sagas/restart_client.py verify <workflow-id> \
  --workflow-runtime php --compensation-runtime python
```

The boundary is a persisted signal wait after the first reserve; verification
requires exactly one wait and signal before the second reservation, one
completion of each reserve, and reverse-order compensation by the selected
runtime. PHP and Python use a signal-derived `ConditionWaitOpened`. Rust
records `SignalWaitOpened`. Match the run ID from `start` to the run ID from
`verify`. Record both parent worker process identities and the signal/verify
order for each run.

Record the UTC time, exact published Server, PHP, Python and Rust versions,
nine completed directions, nine terminal-compensation-failure directions, and
nine restart outcomes on the
[conformance audit](https://github.com/durable-workflow/.github/issues/122).

Stop the workers, then remove only this local stack and its volumes:

```sh
PLAYGROUND_COMPOSE_PROJECT=sample-app-saga scripts/playground down rust
```

## Recover after a compensation commits an external effect

Run this from the repository root with Docker Compose available:

```sh
scripts/sdk-saga-effect-recovery.sh
```

The command uses the repository's pinned published Server and SDKs and
creates its own isolated stack. It runs all nine workflow and compensation
language pairs. In each pair, the second-step compensation commits a reversal
in a separate SQLite service and waits before reporting completion. The runner
observes the commit, kills the compensation worker, and starts a new container.

The replacement must retry the same compensation with a new attempt ID. The
downstream service returns the original reversal for the same operation key.
The client checks both delivery receipts, the original run ID, the timeout
that caused the retry, and the completed reverse-order compensation results.
Each JSON line identifies the run, the two attempts, their worker containers,
and the single downstream effect.
The runner removes its containers, network and disposable ledger on exit.

The downstream service owns idempotency for the operation key. Use an
equivalent application contract when a worker can stop between committing a
reversal and reporting its completion.
