<?php

declare(strict_types=1);

namespace App\Sandbox;

/**
 * The thin sandbox-provider contract.
 *
 * All five lifecycle guarantees the orchestration sample demonstrates correspond
 * to one method on this interface:
 *
 *   1. provision()  - lease a fresh sandbox on demand
 *   2. execute()    - dispatch an agent-decided tool call inside the sandbox
 *   3. snapshot()   - persist workspace state across long runs
 *   4. restore()    - recover after a sandbox disappears (worker migration, eviction)
 *   5. destroy()    - guarantee cleanup at workflow end (success, cancel, failure)
 *
 * Optional suspend()/resume() lets providers idle a sandbox without losing state
 * when an agent is waiting on user input or a downstream signal. Providers that
 * cannot suspend can map both to no-ops; the workflow will still be correct.
 *
 * Implementations live behind activities and are resolved per-activity through
 * the SandboxManager. Workflow code never references this interface directly,
 * so swapping providers is a config change, not a code change.
 */
interface SandboxProvider
{
    public function name(): string;

    /**
     * @param array<string, mixed> $options
     */
    public function provision(array $options = []): SandboxHandle;

    public function execute(SandboxHandle $handle, SandboxToolCall $call): SandboxToolResult;

    /**
     * Suspend an idle sandbox to release compute. The handle stays valid; resume()
     * brings it back. Providers without idle-suspend should treat this as a no-op
     * and return the same handle.
     */
    public function suspend(SandboxHandle $handle): SandboxHandle;

    public function resume(SandboxHandle $handle): SandboxHandle;

    /**
     * Capture sandbox state to provider-managed storage and return a snapshot id
     * that restore() accepts. Snapshots survive worker migrations, restarts, and
     * destroy() of the original sandbox.
     */
    public function snapshot(SandboxHandle $handle): string;

    public function restore(string $snapshotId): SandboxHandle;

    /**
     * Tear down the sandbox unconditionally. Must be safe to call on an already
     * gone sandbox (idempotent) so workflow cleanup paths never throw.
     */
    public function destroy(SandboxHandle $handle): void;
}
