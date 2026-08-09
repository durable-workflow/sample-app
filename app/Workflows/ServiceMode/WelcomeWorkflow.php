<?php

declare(strict_types=1);

namespace App\Workflows\ServiceMode;

use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Worker\WorkflowContext;
use Generator;

final class WelcomeWorkflow
{
    public const TYPE = 'sample.service-mode.welcome';

    public const PHP_TASK_QUEUE = 'sample-service-php';

    public const PYTHON_TASK_QUEUE = 'sample-service-python';

    #[Workflow(self::TYPE)]
    public function run(WorkflowContext $context, string $name): Generator
    {
        $prepared = yield $context->activity(
            PrepareWelcomeActivity::TYPE,
            [$name],
            ['start_to_close_timeout' => 30],
        );

        $decorated = yield $context->activity(
            'sample.service-mode.python.decorate',
            [$prepared],
            [
                'queue' => self::PYTHON_TASK_QUEUE,
                'start_to_close_timeout' => 30,
            ],
        );

        return [
            'message' => $decorated['message'] ?? null,
            'php_activity' => $prepared,
            'python_activity' => $decorated,
        ];
    }
}
