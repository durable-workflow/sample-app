<?php

declare(strict_types=1);

namespace App\Sandbox\Exceptions;

use RuntimeException;

/**
 * Provider could not satisfy a provision request — quota exhausted, region full,
 * malformed config, missing credentials. Activities map this onto NonRetryable so
 * the workflow surfaces a deterministic failure instead of looping on a bad config.
 */
final class SandboxProvisionException extends RuntimeException
{
}
