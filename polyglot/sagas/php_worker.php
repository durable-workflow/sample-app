<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;

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

$client = new Client(
    required('DURABLE_WORKFLOW_RUNTIME_URL'),
    namespace: required('DURABLE_WORKFLOW_NAMESPACE'),
    workerToken: required('DURABLE_WORKFLOW_WORKER_TOKEN'),
);

Worker::create($client, required('DURABLE_WORKFLOW_TASK_QUEUE'))
    ->register(PhpSagaCompensations::class)
    ->run();
