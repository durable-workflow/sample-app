<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Worker;

require __DIR__.'/../php_worker/child_workflows.php';

function required(string $name): string
{
    $value = getenv($name);
    if (! is_string($value) || trim($value) === '') {
        throw new RuntimeException("Set {$name} before starting the worker.");
    }

    return $value;
}

$client = new Client(
    required('DURABLE_WORKFLOW_RUNTIME_URL'),
    namespace: required('DURABLE_WORKFLOW_NAMESPACE'),
    workerToken: required('DURABLE_WORKFLOW_WORKER_TOKEN'),
);

$worker = Worker::create($client, required('DURABLE_WORKFLOW_TASK_QUEUE'));
configureChildWorkflows($worker);
$worker->run();
