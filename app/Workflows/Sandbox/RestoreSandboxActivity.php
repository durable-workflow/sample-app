<?php

declare(strict_types=1);

namespace App\Workflows\Sandbox;

use App\Sandbox\SandboxManager;
use Workflow\V2\Activity;

/**
 * Rebuild a sandbox from a snapshot id. Used by the recovery path when the
 * original sandbox is gone (worker migration, eviction, idle expiry) but the
 * workflow has retained the latest snapshot id and wants to keep going.
 */
class RestoreSandboxActivity extends Activity
{
    public int $tries = 5;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $snapshotId, ?string $provider = null): array
    {
        $manager = app(SandboxManager::class);

        return $manager->driver($provider)
            ->restore($snapshotId)
            ->toArray();
    }
}
