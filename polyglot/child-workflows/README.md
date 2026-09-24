# Cross-language child workflows

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
installed is not evidence of an executed direction. This example proves only
successful child completion; restart, replay, failure, cancellation, and saga
compensation require separate experiments.

Stop the workers, then remove only this local stack and its volumes:

```sh
PLAYGROUND_COMPOSE_PROJECT=sample-app-child-matrix scripts/playground down rust
```
