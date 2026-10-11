<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\V2\Activity;

final class VersionRangeActivity extends Activity
{
    public static int $executions = 0;

    public function handle(array $value): array
    {
        self::$executions++;

        return $value;
    }
}
