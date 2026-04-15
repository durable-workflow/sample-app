<?php

declare(strict_types=1);

namespace App\Workflows\SimpleV2;

use Workflow\V2\Attributes\Activity;

/**
 * Simple V2 activity.
 *
 * Key differences from v1:
 * - Uses #[Activity] attribute (not extends Workflow\Activity)
 * - Method is execute() or any name (attribute declares the activity name)
 * - Return type hints are recommended
 */
#[Activity(name: 'simple-activity-v2')]
class SimpleActivityV2
{
    public function execute(): string
    {
        return 'activity';
    }
}
