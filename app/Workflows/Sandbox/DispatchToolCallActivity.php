<?php

declare(strict_types=1);

namespace App\Workflows\Sandbox;

use App\Sandbox\SandboxHandle;
use App\Sandbox\SandboxManager;
use App\Sandbox\SandboxToolCall;
use Workflow\V2\Activity;

/**
 * Run one agent-decided tool call inside the sandbox.
 *
 * Workflow code passes the sandbox handle plus the tool call as primitive arrays
 * so the v2 Avro codec round-trips them across the worker boundary. The activity
 * rehydrates them, dispatches through the configured provider, and returns the
 * result as an array. SandboxGoneException is allowed to escape so the workflow
 * loop can re-provision and retry the call.
 *
 * tries=4 covers transient network failures during a tool call. Permanent failures
 * (non-zero exit codes from a user command) are not exceptions — they come back
 * as a SandboxToolResult with exit_code != 0 and the workflow decides what to do.
 */
class DispatchToolCallActivity extends Activity
{
    public int $tries = 4;

    /**
     * @param array<string, mixed> $handle
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public function handle(array $handle, array $call): array
    {
        $manager = app(SandboxManager::class);
        $sandboxHandle = SandboxHandle::fromArray($handle);
        $toolCall = SandboxToolCall::fromArray($call);

        $result = $manager->driver($sandboxHandle->provider)
            ->execute($sandboxHandle, $toolCall);

        return $result->toArray();
    }
}
