<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\ActivityFailed;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

function required(string $name): string
{
    $value = getenv($name);
    if (! is_string($value) || trim($value) === '') {
        throw new RuntimeException("Set {$name} before starting the worker.");
    }

    return $value;
}

final class PhpSagaCompensations
{
    #[Activity('sample-app.saga.php.undo-first')]
    public function undoFirst(ActivityContext $context, string $marker): array
    {
        return ['step' => 'first', 'marker' => $marker, 'runtime' => 'php'];
    }

    #[Activity('sample-app.saga.php.undo-second')]
    public function undoSecond(ActivityContext $context, string $marker): array
    {
        return ['step' => 'second', 'marker' => $marker, 'runtime' => 'php'];
    }
}

final class PhpSagaWorkflow
{
    #[Workflow('sample-app.saga.php.compensate-rust')]
    public function run(WorkflowContext $context, string $marker): array
    {
        $saga = $context->saga();

        try {
            foreach (['first', 'second'] as $step) {
                $context->activity("sample-app.saga.reserve-{$step}", [$marker]);
                $saga->addCompensation("sample-app.saga.rust.undo-{$step}", [$marker]);
            }

            $context->activity('sample-app.saga.decline', [], ['retry_policy' => ['max_attempts' => 1]]);

            return ['unexpected_success' => true];
        } catch (ActivityFailed $failure) {
            $saga->compensate($failure);

            return [
                'status' => 'compensated',
                'workflow_runtime' => 'php',
                'compensation_runtime' => 'rust',
                'marker' => $marker,
                'initiating_failure' => $failure->getMessage(),
            ];
        }
    }
}

$client = new Client(
    required('DURABLE_WORKFLOW_RUNTIME_URL'),
    namespace: required('DURABLE_WORKFLOW_NAMESPACE'),
    workerToken: required('DURABLE_WORKFLOW_WORKER_TOKEN'),
);

Worker::create($client, required('DURABLE_WORKFLOW_TASK_QUEUE'))
    ->register(PhpSagaCompensations::class, PhpSagaWorkflow::class)
    ->run();
