<?php

declare(strict_types=1);

namespace App\Workflows\SimpleV2;

use Workflow\V2\Attributes\Activity;

#[Activity(name: 'simple-other-activity-v2')]
class SimpleOtherActivityV2
{
    public function execute(string $string): string
    {
        return $string;
    }
}
