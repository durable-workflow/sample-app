# Sandbox orchestration example

This application is a small integration example for the reusable
[`durable-workflow/ai`](https://github.com/durable-workflow/ai) package. The
package owns provider contracts, handles, typed calls/results, lifecycle
activities, recovery, leases, cleanup, E2B HTTP integration, and Laravel
registration. The app pins the package's `2.0.0-rc.3` release candidate and can
install that published artifact from Packagist. The Sample App keeps only:

- `app/Console/Commands/Sandbox.php`, which starts the package workflow with a
  short tool-call demonstration;
- `config/durable-workflow-ai.php`, which shows local and E2B configuration; and
- this runnable walkthrough.

## Run locally

```bash
php artisan app:sandbox --snapshot-every=2
php artisan app:sandbox --snapshot-every=2 --inject-loss-after=2
```

The `local` provider is a development/test-only subprocess workspace. It runs
commands with the Laravel worker's privileges, is not a security isolation
boundary, and must not execute untrusted input.

The loss-injection command snapshots the workspace, removes the active local
workspace, then demonstrates package recovery. Recovery restores the latest
snapshot and replays every completed later operation, including nonzero exits,
with the original stable operation IDs before continuing.

## Run with E2B

```bash
DURABLE_AI_SANDBOX_DRIVER=e2b \
E2B_API_KEY=… \
E2B_TEMPLATE_ID=base \
php artisan app:sandbox --snapshot-every=2
```

The E2B adapter uses provider TTL plus idempotent destroy while the sandbox is
running. Its suspend and resume capabilities are disabled because pausing
removes the provider TTL; both operations fail before a provider request is
sent. This sample also excludes the workflow suspension argument from its CLI
and MCP launch surfaces. Unsupported lifecycle guarantees fail clearly rather
than becoming silent no-ops.

## Delivery boundary

Every dispatch carries an `operation_id` stable across activity retries and
workflow replay. Both built-in providers currently declare at-least-once tool
effects because neither public provider boundary guarantees atomic effect
deduplication. An uncertain retry after execution but before acknowledgement can
therefore repeat a mutating tool effect.

See the package's
[delivery and recovery contract](https://github.com/durable-workflow/ai/blob/2.0.0-rc.3/docs/delivery-and-recovery.md)
and
[provider-author guide](https://github.com/durable-workflow/ai/blob/2.0.0-rc.3/docs/provider-author-guide.md)
for the versioned public contract and third-party adapter requirements.
