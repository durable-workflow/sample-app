<?php

declare(strict_types=1);

namespace App\Workflows\SimpleV2;

use Workflow\V2\Workflow;
use function Workflow\V2\activity;

/**
 * Simple V2 workflow demonstrating the v1 → v2 migration.
 *
 * Key differences from v1:
 * - extends Workflow\V2\Workflow (not Workflow\Workflow)
 * - use function Workflow\V2\activity (not Workflow\activity)
 * - activity() calls are straight-line (no yield)
 * - entry method is handle() (execute() also works)
 */
class SimpleWorkflowV2 extends Workflow
{
    public function handle(): string
    {
        // V2: No yield — activity() suspends and returns the result directly
        $result = activity(SimpleActivityV2::class);

        $otherResult = activity(SimpleOtherActivityV2::class, 'other');

        return 'workflow_' . $result . '_' . $otherResult;
    }
}
