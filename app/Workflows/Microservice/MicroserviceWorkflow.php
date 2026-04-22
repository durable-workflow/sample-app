<?php

declare(strict_types=1);

namespace App\Workflows\Microservice;

use Workflow\V2\Workflow;

use function Workflow\V2\activity;

class MicroserviceWorkflow extends Workflow
{
    public function handle(): string
    {
        // The workflow coordinates the durable order of work; the activities
        // can run in another Laravel app that shares the queue/database contract.
        $result = activity(MicroserviceActivity::class);

        $otherResult = activity(MicroserviceOtherActivity::class, 'other');

        return 'workflow_'.$result.'_'.$otherResult;
    }
}
