<?php

declare(strict_types=1);

namespace App\Workflows\Sandbox;

use App\Sandbox\Exceptions\SandboxGoneException;
use Throwable;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;

/**
 * Reference workflow for the durable sandbox orchestration pattern.
 *
 * Demonstrates the five lifecycle guarantees an agent developer otherwise has
 * to rebuild from scratch:
 *
 *   1. Provision-on-demand:   ProvisionSandboxActivity runs at workflow start.
 *   2. Tool-call dispatch:    DispatchToolCallActivity carries each agent-decided
 *                             call to the sandbox and returns the result.
 *   3. Persistence:           SnapshotSandboxActivity captures workspace state at
 *                             every snapshot interval; the snapshot id is durable
 *                             across worker migrations and restarts.
 *   4. Recovery:              SandboxGoneException is caught at the workflow level
 *                             and triggers re-provision + restore from the latest
 *                             snapshot. The agent transcript is replayed onto a
 *                             fresh sandbox without losing prior context.
 *   5. Cleanup:               DestroySandboxActivity is invoked from the finally
 *                             block, guaranteeing it runs on success, cancel, and
 *                             failure paths alike.
 *
 * The provider abstraction (App\Sandbox\SandboxProvider) keeps this workflow
 * provider-agnostic: swapping LocalSandboxProvider for E2bSandboxProvider (or a
 * custom provider) is a config edit, not a code edit.
 */
class SandboxAgentWorkflow extends Workflow
{
    private const MAX_RECOVERIES = 3;

    /**
     * @param array<int, array<string, mixed>> $toolCalls
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function handle(
        array $toolCalls,
        ?string $provider = null,
        int $snapshotEveryNCalls = 0,
        bool $suspendBetweenCalls = false,
        array $options = [],
    ): array {
        $handle = activity(ProvisionSandboxActivity::class, $provider, $options);

        $results = [];
        $latestSnapshot = null;
        $recoveryCount = 0;

        try {
            $i = 0;

            while ($i < count($toolCalls)) {
                $call = $toolCalls[$i];

                try {
                    $results[] = activity(DispatchToolCallActivity::class, $handle, $call);
                } catch (Throwable $th) {
                    if (! self::isSandboxGone($th)) {
                        throw $th;
                    }

                    if ($recoveryCount >= self::MAX_RECOVERIES) {
                        throw new SandboxGoneException('Sandbox lost too many times; aborting agent run.');
                    }

                    $recoveryCount++;
                    $handle = $this->recoverSandbox($latestSnapshot, $provider, $options);

                    continue;
                }

                $i++;

                if ($snapshotEveryNCalls > 0 && $i % $snapshotEveryNCalls === 0) {
                    $latestSnapshot = activity(SnapshotSandboxActivity::class, $handle);
                }

                if ($suspendBetweenCalls && $i < count($toolCalls)) {
                    $handle = activity(SuspendSandboxActivity::class, $handle);
                    $handle = activity(ResumeSandboxActivity::class, $handle);
                }
            }

            return [
                'sandbox_id' => $handle['id'],
                'provider' => $handle['provider'],
                'tool_results' => $results,
                'latest_snapshot' => $latestSnapshot,
                'recovery_count' => $recoveryCount,
            ];
        } finally {
            try {
                activity(DestroySandboxActivity::class, $handle);
            } catch (Throwable) {
                // The destroy activity is itself best-effort and swallows provider
                // errors. We keep this guard so finalization never masks the
                // workflow's actual outcome (success, cancel, or failure).
            }
        }
    }

    /**
     * Treat the activity-side failure as a "sandbox is gone" event whether the
     * runtime restored the original SandboxGoneException class or wrapped the
     * payload in RestoredWorkflowException because the original class could not
     * be rebuilt across the worker boundary.
     */
    private static function isSandboxGone(Throwable $throwable): bool
    {
        if ($throwable instanceof SandboxGoneException) {
            return true;
        }

        return $throwable instanceof RestoredWorkflowException
            && $throwable->originalExceptionClass() === SandboxGoneException::class;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function recoverSandbox(
        ?string $latestSnapshot,
        ?string $provider,
        array $options,
    ): array {
        if ($latestSnapshot !== null) {
            return activity(RestoreSandboxActivity::class, $latestSnapshot, $provider);
        }

        return activity(ProvisionSandboxActivity::class, $provider, $options);
    }
}
