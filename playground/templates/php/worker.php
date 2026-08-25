<?php

declare(strict_types=1);

use DurableWorkflow\Bridge\Laravel\ProcessCredentialResolver;
use DurableWorkflow\Bridge\ServiceConfiguration;
use DurableWorkflow\Worker;
use Psr\Log\LoggerInterface;

[$app] = require __DIR__.'/bootstrap.php';
$configuration = $app->make(ServiceConfiguration::class);
$workerId = trim((string) getenv('DURABLE_WORKFLOW_WORKER_ID'));
if ($workerId === '') {
    throw new RuntimeException('Set DURABLE_WORKFLOW_WORKER_ID before starting the worker.');
}
$worker = new Worker(
    ProcessCredentialResolver::workerClient($configuration),
    $configuration->taskQueue((string) getenv('DURABLE_WORKFLOW_TASK_QUEUE')),
    workerId: $workerId,
    container: $app,
    logger: $app->make(LoggerInterface::class),
);
$handlers = array_map(
    static fn (string $handler): object => $app->make($handler),
    $configuration->handlers,
);
$worker->register(...$handlers)->run($configuration->pollTimeoutSeconds);
