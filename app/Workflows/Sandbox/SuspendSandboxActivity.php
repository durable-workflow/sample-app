<?php

declare(strict_types=1);

namespace App\Workflows\Sandbox;

use App\Sandbox\SandboxHandle;
use App\Sandbox\SandboxManager;
use Workflow\V2\Activity;

/**
 * Idle-suspend the sandbox to release compute while the agent waits for a
 * downstream signal or user input. Providers that do not support suspend (the
 * local subprocess provider, for example) treat the call as a no-op so the
 * workflow stays portable.
 */
class SuspendSandboxActivity extends Activity
{
    public int $tries = 3;

    /**
     * @param array<string, mixed> $handle
     * @return array<string, mixed>
     */
    public function handle(array $handle): array
    {
        $manager = app(SandboxManager::class);
        $sandboxHandle = SandboxHandle::fromArray($handle);

        return $manager->driver($sandboxHandle->provider)
            ->suspend($sandboxHandle)
            ->toArray();
    }
}
