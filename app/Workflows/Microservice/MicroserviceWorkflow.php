<?php

declare(strict_types=1);

namespace App\Workflows\Microservice;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

class MicroserviceWorkflow extends Workflow
{
    public function handle(): string
    {
        $result = activity(MicroserviceActivity::class);

        $otherResult = activity(MicroserviceOtherActivity::class, 'other');

        return 'workflow_' . $result . '_' . $otherResult;
    }
}
