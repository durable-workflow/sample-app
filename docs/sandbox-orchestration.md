# Sandbox Orchestration Pattern

This sample shows how to drive an ephemeral agent sandbox from a durable workflow without rebuilding lifecycle plumbing for every project. It is the durable-workflow equivalent of the "sandbox orchestration harness" pattern that other AI/agent platforms ship as reference material — and it sits entirely on existing v2 Activity primitives, with no new server-side machinery.

## What the sample covers

A long-running coding agent — or any agent that needs a place to run shell commands, edit files, and install dependencies — depends on five lifecycle guarantees. Each one corresponds to one activity in this sample:

| Guarantee | Activity | Why workflow code can rely on it |
|-----------|----------|----------------------------------|
| Provision on demand | `ProvisionSandboxActivity` | Provider lease happens inside an activity, so the workflow only stores a handle id. |
| Drive execution from agent intent | `DispatchToolCallActivity` | LLM tool-call decisions reach the sandbox through one activity, the result lands in workflow history. |
| Persist state across long runs | `SnapshotSandboxActivity` | Workspace state is captured to provider-managed storage; the snapshot id is durable across worker migrations and worker restarts. |
| Recover from sandbox loss | `RestoreSandboxActivity` | When the sandbox is gone, the workflow loop catches `SandboxGoneException`, restores from the latest snapshot, and resumes. |
| Clean up at the right time | `DestroySandboxActivity` | Invoked from a `try/finally` block, so success, cancel, and failure paths all tear the sandbox down — no orphan compute spend. |

Optional `SuspendSandboxActivity` and `ResumeSandboxActivity` cover idle-suspend for providers that bill by active minute; providers without idle support implement them as no-ops, so the workflow stays portable.

## Files in the sample

```
app/Sandbox/
  SandboxProvider.php                    # the contract (provision/execute/suspend/resume/snapshot/restore/destroy)
  SandboxHandle.php                      # opaque handle DTO
  SandboxToolCall.php                    # {type, args} DTO
  SandboxToolResult.php                  # {exit_code, stdout, stderr} DTO
  SandboxConfig.php                      # facade over config('sandbox')
  SandboxManager.php                     # resolves a SandboxProvider by config name
  Exceptions/
    SandboxGoneException.php             # NonRetryable — workflow loop owns the recovery decision
    SandboxProvisionException.php        # mapped to NonRetryable inside ProvisionSandboxActivity
  Providers/
    LocalSandboxProvider.php             # subprocess-backed; ships with the sample
    E2bSandboxProvider.php               # E2B Cloud HTTP integration

app/Workflows/Sandbox/
  SandboxAgentWorkflow.php               # the orchestration workflow
  ProvisionSandboxActivity.php           # tries=5; SandboxProvisionException -> NonRetryable
  DispatchToolCallActivity.php           # tries=4; SandboxGoneException is non-retryable, propagates to the workflow
  SnapshotSandboxActivity.php            # tries=3
  RestoreSandboxActivity.php             # tries=5
  SuspendSandboxActivity.php             # tries=3 (no-op on providers without idle)
  ResumeSandboxActivity.php              # tries=5
  DestroySandboxActivity.php             # tries=3; swallows provider errors so cleanup is best-effort

config/sandbox.php                       # default driver + per-driver config
```

## Run it

The default `local` driver runs each tool call as a subprocess against a per-sandbox workspace directory under `storage_path('sandbox/workspaces')`. It needs no API keys and is the easiest way to see the full lifecycle.

```bash
php artisan app:sandbox                              # local subprocess provider, no snapshots
php artisan app:sandbox --snapshot-every=2           # snapshot every 2 tool calls
php artisan app:sandbox --suspend-between            # suspend + resume between calls
php artisan app:sandbox --snapshot-every=2 --inject-loss-after=2  # force local restore
```

To run the same workflow against E2B Cloud:

```bash
SANDBOX_DRIVER=e2b E2B_API_KEY=… php artisan app:sandbox --snapshot-every=2
```

The workflow class does not change when you switch providers. Only `config/sandbox.php` changes.

## Add a third provider

Implement `App\Sandbox\SandboxProvider` for the third-party API (Modal, Daytona, GKE Agent Sandbox, Bedrock AgentCore Runtime, an internal sandbox service, …). Register a factory through `SandboxManager::extend()` from your service provider:

```php
$this->app->afterResolving(SandboxManager::class, function (SandboxManager $manager): void {
    $manager->extend('modal', static fn ($app, $config): SandboxProvider
        => new ModalSandboxProvider(
            apiKey: (string) $config['api_key'],
            // …
        ));
});
```

Add the corresponding entry to `config/sandbox.php` and set `SANDBOX_DRIVER=modal`. Workflow code stays identical because every lifecycle path goes through the activity layer, not directly through the provider.

## Retry posture

The activities classify failures so the workflow stays simple:

- **Permanent provider failures** (missing credentials, bad template, quota exceeded) → `SandboxProvisionException` inside `ProvisionSandboxActivity` is converted to `NonRetryableException`. The workflow surfaces a deterministic failure rather than burning retry budget.
- **Sandbox lost mid-run** → `SandboxGoneException` implements `NonRetryableExceptionContract` so the activity does not retry. The workflow's catch block restores from the latest snapshot and the run continues.
- **Transient provider failures** (network blips, rate limits) → plain `RuntimeException`. The activity's `$tries` budget covers them automatically.

## Cleanup posture

`SandboxAgentWorkflow::handle()` wraps the entire run in `try { … } finally { activity(DestroySandboxActivity::class, $handle); }`. PHP's `finally` runs on every termination path:

- success → finally runs, sandbox destroyed.
- workflow cancellation (`WorkflowCancelledException`) → finally runs, sandbox destroyed.
- unrecoverable failure → finally runs, sandbox destroyed.

`SandboxProvider::destroy()` is required to be idempotent so a duplicate cleanup attempt — or a destroy against a sandbox that was already gone — is always safe.
