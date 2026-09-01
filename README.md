# Durable Workflow Sample App

[![Application tests](https://github.com/durable-workflow/sample-app/actions/workflows/ci.yml/badge.svg)](https://github.com/durable-workflow/sample-app/actions/workflows/ci.yml)
[![Embedded smoke](https://github.com/durable-workflow/sample-app/actions/workflows/smoke.yml/badge.svg)](https://github.com/durable-workflow/sample-app/actions/workflows/smoke.yml)
[![Polyglot smoke](https://github.com/durable-workflow/sample-app/actions/workflows/polyglot-validation.yml/badge.svg)](https://github.com/durable-workflow/sample-app/actions/workflows/polyglot-validation.yml)
[![Development image](https://github.com/durable-workflow/sample-app/actions/workflows/devcontainer-image.yml/badge.svg)](https://github.com/durable-workflow/sample-app/actions/workflows/devcontainer-image.yml)

Runnable examples for Durable Workflow 2.0. Start a PHP-authored workflow
that calls Python and Rust activities through a standalone Server, build a
workflow with any first-party SDK, or run the workflow engine inside Laravel.

[![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/durable-workflow/sample-app?quickstart=1)

## Choose a path

| Path | Use it when | Command |
| --- | --- | --- |
| [Service mode](#service-mode) | PHP, Python, and Rust workers share a standalone or managed runtime | `scripts/polyglot.sh` |
| [SDK playground](#sdk-playground) | You want to author a small workflow and activity in one SDK | `scripts/playground php`, `python`, or `rust` |
| [Embedded Laravel](#embedded-laravel) | Laravel owns workflow execution and storage | `composer run dev` |

The Codespaces image already contains PHP, Composer, Python, Rust, Cargo,
Docker Compose, Node, Chromium, `dw`, and `rg`. Setup installs only this
repository's Composer and npm dependencies.

## Service mode

Run the featured `PolyglotWorkflow`:

```bash
scripts/polyglot.sh
```

The command starts a published Durable Workflow Server and three workers. A
PHP workflow sends an order calculation to a Python activity, then sends that
result to a Rust activity that produces a receipt. The final result identifies
all three runtimes and the artifact versions used for the run.

The isolated `sample-app-polyglot-demo` Compose project remains available for
inspection. Stop it with:

```bash
scripts/polyglot.sh down
```

See [polyglot/README.md](polyglot/README.md) for the worker layout and the
larger directional runtime matrix.

## SDK playground

Use the same interface for each first-party SDK:

```bash
scripts/playground php
scripts/playground python
scripts/playground rust
```

Each command creates editable source under `.playground/<language>`, starts an
isolated local Server and Waterline, waits for the worker registration, starts
the workflow, verifies its result and history, and prints the matching
Waterline run URL. Existing authored files are preserved.

Create a caller-owned project elsewhere with `--source`:

```bash
scripts/playground rust --source "$HOME/my-durable-rust-worker"
```

Inspect the effective contract without starting anything:

```bash
scripts/playground doctor
scripts/playground rust --print-contract
```

Remove one playground's containers and state with:

```bash
scripts/playground down rust
```

### Managed runtime

The same authored project can run against Durable Workflow Cloud or another
existing runtime. Supply role-specific credentials without putting them in
source control:

```bash
export DURABLE_WORKFLOW_WORKER_TOKEN='<worker credential>'
export DURABLE_WORKFLOW_CLIENT_TOKEN='<client credential>'

scripts/playground rust \
  --runtime managed \
  --runtime-url 'https://runtime.example/namespaces/example' \
  --namespace 'example' \
  --task-queue 'my-rust-worker'
```

The worker receives only the worker credential, and the client receives only
the client credential. The runner prints the workflow type, activity type,
task queue, worker command, start command, and expected result before it runs.

## Embedded Laravel

Start Laravel's web server, queue worker, logs, and Vite process:

```bash
composer run dev
```

In a second terminal, start the example workflow:

```bash
php artisan app:workflow
```

Open `/waterline` on the forwarded application URL to inspect the run. Run the
Laravel workflow and activity tests with:

```bash
php artisan test
```

The embedded examples live under [app/Workflows](app/Workflows), with focused
tests under [tests/Feature/Workflows](tests/Feature/Workflows).

## Example index

| Example | What it demonstrates |
| --- | --- |
| `SimpleWorkflow` | Minimal embedded workflow and activity |
| `AccountOnboardingWorkflow` | Signals, timers, and external interaction |
| `BatchProcessingWorkflow` | Bounded concurrency and fan-out |
| `DataPipelineWorkflow` | Multi-step activity orchestration |
| `DeployWorkflow` | Child workflows and deployment stages |
| `SagaWorkflow` | Compensation after a failed step |
| `SubscriptionWorkflow` | Long-running lifecycle and cancellation |
| `SandboxAgentWorkflow` | Durable sandbox provisioning, snapshots, recovery, and cleanup |
| `PolyglotWorkflow` | PHP workflow with Python and Rust activities |

## Repository map

| Path | Purpose |
| --- | --- |
| `app/Workflows/` | Embedded Laravel examples |
| `polyglot/` | Service-mode workers and Compose topology |
| `playground/` | PHP, Python, and Rust authoring templates |
| `scripts/polyglot.sh` | Featured PHP to Python to Rust run |
| `scripts/playground` | Symmetric SDK authoring runner |
| `.devcontainer/` | Prepared Codespaces development image |

## Local development

Codespaces is the shortest path. For an existing PHP 8.4, Node, Docker, and
Docker Compose environment:

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
docker compose up -d
php artisan migrate
composer run dev
```

Run the focused checks with:

```bash
php artisan test
npm test
npm run build
```

## Documentation

- [Durable Workflow documentation](https://durable-workflow.com/docs/2.0/)
- [PHP SDK](https://php.durable-workflow.com/)
- [Python SDK](https://python.durable-workflow.com/)
- [Rust SDK](https://rust.durable-workflow.com/)
- [Durable Workflow Cloud](https://cloud.durable-workflow.com/)

The Laravel 12 / Durable Workflow 1.x sample remains available on the
[`Laravel-12` branch](https://github.com/durable-workflow/sample-app/tree/Laravel-12).

## License

Durable Workflow Sample App is open source software licensed under the
[MIT license](LICENSE).
