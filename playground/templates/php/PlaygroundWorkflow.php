<?php

declare(strict_types=1);

namespace SampleAppPlayground;

use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Worker\WorkflowContext;

final class PlaygroundWorkflow
{
    /** @return array{greeting: string, activity_runtime: string, workflow_runtime: string} */
    #[Workflow(Scenario::WORKFLOW_TYPE)]
    public function run(WorkflowContext $context): array
    {
        $activity = $context->activity(
            Scenario::ACTIVITY_TYPE,
            [],
            ['start_to_close_timeout' => 30],
        );

        return [
            ...$activity,
            'workflow_runtime' => 'php',
        ];
    }
}
