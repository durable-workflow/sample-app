<?php

declare(strict_types=1);

namespace App\Workflows\Sandbox;

use App\Sandbox\SandboxHandle;
use App\Sandbox\SandboxManager;
use Workflow\V2\Activity;

/**
 * Bring an idle-suspended sandbox back online before the next tool call. Pairs
 * with SuspendSandboxActivity; both are no-ops on providers without idle support.
 */
class ResumeSandboxActivity extends Activity
{
    public int $tries = 5;

    /**
     * @param array<string, mixed> $handle
     * @return array<string, mixed>
     */
    public function handle(array $handle): array
    {
        $manager = app(SandboxManager::class);
        $sandboxHandle = SandboxHandle::fromArray($handle);

        return $manager->driver($sandboxHandle->provider)
            ->resume($sandboxHandle)
            ->toArray();
    }
}
