# Sandbox orchestration example

This application is a small integration example for the reusable
[`durable-workflow/ai`](https://github.com/durable-workflow/ai) package. The
package owns provider contracts, handles, typed calls/results, lifecycle
activities, recovery, leases, cleanup, E2B HTTP integration, and Laravel
registration. Composer resolves the package's `main` source contract, while
`composer.lock` retains the exact public commit selected for this reproducible
Sample App build. The Sample App keeps only:

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

The loss-injection command snapshots the workspace, then runs a dedicated local
lifecycle injection after the second completed tool call. The injection removes
the active workspace and enters package recovery without becoming a successful
tool result or a reconstruction-journal entry. Recovery restores the latest
snapshot, preserves the `README.md` written before that checkpoint, and
continues with the read and final shell call. Genuine completed operations after
a snapshot are still replayed with their original stable operation IDs.

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
[delivery and recovery contract](https://github.com/durable-workflow/ai/blob/e2fee66bb83d01b9df54f436a753e524895130f3/docs/delivery-and-recovery.md)
and
[provider-author guide](https://github.com/durable-workflow/ai/blob/e2fee66bb83d01b9df54f436a753e524895130f3/docs/provider-author-guide.md)
for the supported-channel contract and third-party adapter requirements. Use
the package ref recorded in `composer.lock` when reproducing this exact build.
Composer updates synchronize these immutable link targets from that lock entry.
