<?php

declare(strict_types=1);

namespace App\Polyglot;

use Workflow\V2\Support\ActivityCall;

/**
 * Outcome of a single Fiber step in {@see WorkflowFiberRunner}.
 *
 * Either the workflow returned (`completed = true`, with a `result`),
 * or it suspended with an `ActivityCall` (`activity` is non-null).
 */
final class WorkflowStep
{
    private function __construct(
        public readonly bool $completed,
        public readonly mixed $result,
        public readonly ?ActivityCall $activity,
        public readonly ?string $signalName,
        public readonly ?int $signalTimeoutSeconds,
    ) {
    }

    public static function completed(mixed $result): self
    {
        return new self(true, $result, null, null, null);
    }

    public static function scheduleActivity(ActivityCall $call): self
    {
        return new self(false, null, $call, null, null);
    }

    public static function waitForSignal(string $signalName, ?int $timeoutSeconds): self
    {
        return new self(false, null, null, $signalName, $timeoutSeconds);
    }
}
