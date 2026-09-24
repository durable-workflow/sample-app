<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Attribute\Signal;
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
        if (str_starts_with($marker, 'saga-fail-compensation-')) {
            throw new RuntimeException('planned undo-second compensation failure');
        }

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
                $saga->addCompensation("sample-app.saga.rust.undo-{$step}", [$marker], ['retry_policy' => ['max_attempts' => 1]]);
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

final class PhpSagaRestartWorkflow
{
    #[Workflow('sample-app.saga.php.restart-compensate-rust')]
    public function run(WorkflowContext $context, string $marker): array
    {
        $saga = $context->saga();

        try {
            $context->activity('sample-app.saga.reserve-first', [$marker]);
            $saga->addCompensation('sample-app.saga.rust.undo-first', [$marker], ['retry_policy' => ['max_attempts' => 1]]);
            $context->waitCondition(
                fn (): bool => $context->signals('sample-app.saga.restart-continue') !== [],
                key: 'saga-restart-continue',
            );
            $context->activity('sample-app.saga.reserve-second', [$marker]);
            $saga->addCompensation('sample-app.saga.rust.undo-second', [$marker], ['retry_policy' => ['max_attempts' => 1]]);
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

    #[Signal('sample-app.saga.restart-continue')]
    public function resume(): void
    {
        // The committed signal is read from history by the wait predicate.
    }
}

$client = new Client(
    required('DURABLE_WORKFLOW_RUNTIME_URL'),
    namespace: required('DURABLE_WORKFLOW_NAMESPACE'),
    workerToken: required('DURABLE_WORKFLOW_WORKER_TOKEN'),
);

Worker::create($client, required('DURABLE_WORKFLOW_TASK_QUEUE'))
    ->register(PhpSagaCompensations::class, PhpSagaWorkflow::class, PhpSagaRestartWorkflow::class)
    ->run();
