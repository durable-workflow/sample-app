<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;
use function Workflow\V2\getVersion;

final class VersionRangeWorkflow extends Workflow
{
    // Changing this range simulates deploying an incompatible definition.
    public static int $version = 1;

    public function handle(array $value): array
    {
        getVersion('sample-range', self::$version, self::$version);

        return activity(VersionRangeActivity::class, $value);
    }
}
