<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use DurableWorkflow\Attribute\Activity;
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
    public function undoSecond(ActivityContext $context, string $marker, bool $recoverEffect = false): array
    {
        if (str_starts_with($marker, 'saga-fail-compensation-')) {
            throw new RuntimeException('planned undo-second compensation failure');
        }

        if ($recoverEffect) {
            $response = (new GuzzleHttp\Client(['timeout' => 5]))->post(required('ACTIVITY_EFFECTS_URL').'/effects', [
                'json' => [
                    'operation_key' => $marker.':undo-second',
                    'attempt_id' => $context->activityAttemptId,
                    'input' => ['operation' => 'undo-second', 'marker' => $marker],
                ],
            ]);
            $receipt = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            if ($receipt['reused'] !== ($context->attemptNumber === 2)) {
                throw new RuntimeException('Unexpected downstream compensation receipt.');
            }
            if ($context->attemptNumber === 1) {
                sleep(45);
                throw new RuntimeException('Fixture did not stop the first compensation worker.');
            }
        }

        return ['step' => 'second', 'marker' => $marker, 'runtime' => 'php'];
    }
}

function runSaga(
    WorkflowContext $context,
    string $marker,
    string $compensationRuntime,
    bool $waitForRestart = false,
    bool $recoverEffect = false,
): array {
    $saga = $context->saga();

    try {
        foreach (['first', 'second'] as $step) {
            $context->activity("sample-app.saga.reserve-{$step}", [$marker]);
            $recoverStep = $recoverEffect && $step === 'second';
            $saga->addCompensation(
                "sample-app.saga.{$compensationRuntime}.undo-{$step}",
                $recoverStep ? [$marker, true] : [$marker],
                $recoverStep
                    ? ['start_to_close_timeout' => 20, 'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [1]]]
                    : ['retry_policy' => ['max_attempts' => 1]],
            );
            if ($waitForRestart && $step === 'first') {
                $context->waitCondition(
                    fn (): bool => $context->signals('sample-app.saga.restart-continue') !== [],
                    key: 'saga-restart-continue',
                );
            }
        }

        $context->activity('sample-app.saga.decline', [], ['retry_policy' => ['max_attempts' => 1]]);

        return ['unexpected_success' => true];
    } catch (ActivityFailed $failure) {
        $saga->compensate($failure);

        return [
            'status' => 'compensated',
            'workflow_runtime' => 'php',
            'compensation_runtime' => $compensationRuntime,
            'marker' => $marker,
            'initiating_failure' => $failure->getMessage(),
        ];
    }
}

$client = new Client(
    required('DURABLE_WORKFLOW_RUNTIME_URL'),
    namespace: required('DURABLE_WORKFLOW_NAMESPACE'),
    workerToken: required('DURABLE_WORKFLOW_WORKER_TOKEN'),
);

$worker = Worker::create($client, required('DURABLE_WORKFLOW_TASK_QUEUE'))
    ->register(PhpSagaCompensations::class);
foreach (['php', 'python', 'rust'] as $runtime) {
    $worker->registerWorkflow(
        "sample-app.saga.php.compensate-{$runtime}",
        static fn (WorkflowContext $context, string $marker, string $scenario = ''): array => runSaga(
            $context, $marker, $runtime, recoverEffect: $scenario === 'recover-effect',
        ),
    );
    $restartType = "sample-app.saga.php.restart-compensate-{$runtime}";
    $worker->registerWorkflow(
        $restartType,
        static fn (WorkflowContext $context, string $marker): array => runSaga($context, $marker, $runtime, true),
    );
    $worker->declareSignal($restartType, 'sample-app.saga.restart-continue');
}
$worker->run();
