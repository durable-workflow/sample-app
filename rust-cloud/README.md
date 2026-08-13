# Rust Cloud quickstart worker

This small development-mode worker is the Sample App's entry point for Durable
Workflow Cloud controlled early access. It registers
`sample.rust-cloud.greeter` and `sample.rust-cloud.greet` on
`rust-cloud-quickstart`, then shuts down cleanly on Ctrl+C.

From a prepared Codespace, follow the dedicated [Rust Cloud
quickstart](https://durable-workflow.com/docs/2.0/polyglot/rust-cloud-quickstart/).
Use `scripts/rust-cloud.sh run` for the complete one-terminal path or
`scripts/rust-cloud.sh worker` when starting the CLI command separately.

The source manifest and checked-in lockfile pin the current supported Rust SDK
release. The entry script verifies the published artifact tuple, then uses the
locked graph for version evidence and builds. It removes the client and worker
credentials from its ambient environment before resolution and compilation,
then exposes the runtime settings and worker credential only in the worker
environment and the client credential only in the workflow CLI environment.
