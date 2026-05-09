<?php

declare(strict_types=1);

namespace App\Sandbox\Exceptions;

use RuntimeException;
use Workflow\Exceptions\NonRetryableExceptionContract;

/**
 * Provider knows the sandbox no longer exists (terminated, evicted, expired).
 *
 * Workflow code catches this exception across the activity boundary and re-provisions
 * a fresh sandbox, optionally restoring from the latest snapshot. We mark it
 * non-retryable so the activity layer does not waste exponential backoff retries
 * against an already-gone sandbox — the workflow loop owns the recovery decision.
 *
 * Transient failures (network blips, provider rate limits) raise plain RuntimeException
 * subclasses instead and benefit from activity-level retries.
 */
final class SandboxGoneException extends RuntimeException implements NonRetryableExceptionContract
{
}
