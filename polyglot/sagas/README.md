# Rust saga compensation across SDKs

This experiment runs Rust, PHP and Python workflows that reserve two steps,
encounter an intentional activity failure, then compensate in reverse order.
It covers Rust workflows with Rust, PHP and Python compensation, plus PHP and
Python workflows with Rust compensation. It checks the completed result and
persisted activity order, not external business side effects. This complements
the published Server saga runner, which currently executes PHP/Python scenarios
but no Rust handler.

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

The client reports each completed direction and ends with `5/5 Rust-involving
saga compensation directions completed`. A missing handler, mismatched activity
type, absent planned failure, or out-of-order compensation fails the run.

To check Rust cold replay with each compensation runtime, leave the disposable
stack up. Run the Rust worker and the PHP and Python workers above. For each of
`rust`, `php`, and `python`, start one workflow in another terminal:

```sh
python polyglot/sagas/restart_client.py start --compensation-runtime php
```

After it prints `restart_boundary`, stop the Rust worker and wait for that
process to exit. While it is stopped, deliver the signal using the printed ID:

```sh
python polyglot/sagas/restart_client.py signal <workflow-id>
```

Start a new Rust worker process, keeping the PHP and Python workers running,
then verify the resumed run with the same compensation runtime:

```sh
python polyglot/sagas/restart_client.py verify <workflow-id> --compensation-runtime php
```

The boundary is a persisted signal wait after the first reserve; verification
requires exactly one wait and signal, no duplicated reserve, and reverse-order
compensation by the selected runtime. Record both Rust worker process identities
and the signal/verify order for each run. These checks cover Rust workflows
restarted before Rust, PHP, or Python compensation, not process loss during an
activity or PHP/Python-parent restarts.

Record the UTC time, exact published Server, PHP, Python and Rust versions,
five outcomes and any failure on the owning GitHub issue. This example does
not qualify duplicate delivery or compensation failure; those require separate
tests.

Stop the workers, then remove only this local stack and its volumes:

```sh
PLAYGROUND_COMPOSE_PROJECT=sample-app-saga scripts/playground down rust
```
