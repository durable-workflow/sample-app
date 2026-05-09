<?php

declare(strict_types=1);

namespace App\Workflows\Sandbox;

use App\Sandbox\SandboxHandle;
use App\Sandbox\SandboxManager;
use Throwable;
use Workflow\V2\Activity;

/**
 * Tear the sandbox down. The workflow registers this activity through a
 * try/finally so cleanup runs deterministically on every termination path —
 * success, cancellation, or failure — and there are no orphan sandboxes burning
 * compute spend after a run ends.
 *
 * The activity catches and swallows provider errors so a flaky destroy() never
 * keeps the workflow from finalizing. The provider contract requires destroy()
 * to be idempotent, so a duplicate cleanup attempt is safe.
 */
class DestroySandboxActivity extends Activity
{
    public int $tries = 3;

    /**
     * @param array<string, mixed> $handle
     */
    public function handle(array $handle): bool
    {
        $manager = app(SandboxManager::class);

        try {
            $sandboxHandle = SandboxHandle::fromArray($handle);
            $manager->driver($sandboxHandle->provider)->destroy($sandboxHandle);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
