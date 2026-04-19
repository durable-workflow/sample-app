<?php

declare(strict_types=1);

namespace App\Workflows\Simple;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

class SimpleWorkflow extends Workflow
{
    public function handle(): string
    {
        $result = activity(SimpleActivity::class);

        $otherResult = activity(SimpleOtherActivity::class, 'other');

        return 'workflow_' . $result . '_' . $otherResult;
    }
}
