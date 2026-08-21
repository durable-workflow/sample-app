<?php

declare(strict_types=1);

namespace SampleAppPlayground;

use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Worker\WorkflowContext;

final class PlaygroundWorkflow
{
    /**
     * @param  array{name: string}  $input
     * @return array{greeting: string, input: array{name: string}, activity_runtime: string, workflow_runtime: string}
     */
    #[Workflow(Scenario::WORKFLOW_TYPE)]
    public function run(WorkflowContext $context, array $input): array
    {
        $activity = $context->activity(
            Scenario::ACTIVITY_TYPE,
            [$input],
            ['start_to_close_timeout' => 30],
        );

        return [
            ...$activity,
            'workflow_runtime' => 'php',
        ];
    }
}
