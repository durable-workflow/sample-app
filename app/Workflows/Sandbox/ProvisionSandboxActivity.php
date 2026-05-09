<?php

declare(strict_types=1);

namespace App\Workflows\Sandbox;

use App\Sandbox\Exceptions\SandboxProvisionException;
use App\Sandbox\SandboxHandle;
use App\Sandbox\SandboxManager;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

/**
 * Provision a fresh sandbox or restore one from a snapshot.
 *
 * The default tries=5 + exponential backoff covers transient provider failures
 * (rate limits, network blips, region warmups). Provisioning failures that the
 * provider classifies as permanent (missing credentials, malformed template,
 * quota exhaustion) are converted to NonRetryable so the workflow surfaces a
 * deterministic failure instead of looping on a bad config.
 */
class ProvisionSandboxActivity extends Activity
{
    public int $tries = 5;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function handle(?string $provider = null, array $options = []): array
    {
        $manager = app(SandboxManager::class);

        try {
            $handle = $manager->driver($provider)->provision($options);
        } catch (SandboxProvisionException $e) {
            throw new NonRetryableException($e->getMessage(), previous: $e);
        }

        return $handle->toArray();
    }
}
