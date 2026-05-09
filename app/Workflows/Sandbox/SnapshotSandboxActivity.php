<?php

declare(strict_types=1);

namespace App\Workflows\Sandbox;

use App\Sandbox\SandboxHandle;
use App\Sandbox\SandboxManager;
use Workflow\V2\Activity;

/**
 * Capture sandbox state as a snapshot id durable enough to survive worker
 * migrations, restarts, and destruction of the original sandbox. The workflow
 * stores the returned snapshot id locally so RestoreSandboxActivity can rebuild
 * the workspace if the sandbox is lost mid-run.
 */
class SnapshotSandboxActivity extends Activity
{
    public int $tries = 3;

    /**
     * @param array<string, mixed> $handle
     */
    public function handle(array $handle): string
    {
        $manager = app(SandboxManager::class);
        $sandboxHandle = SandboxHandle::fromArray($handle);

        return $manager->driver($sandboxHandle->provider)
            ->snapshot($sandboxHandle);
    }
}
