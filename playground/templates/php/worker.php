<?php

declare(strict_types=1);

use DurableWorkflow\Bridge\Laravel\WorkerFactory;

[$app] = require __DIR__.'/bootstrap.php';
$factory = $app->make(WorkerFactory::class);
$factory->make((string) getenv('DURABLE_WORKFLOW_TASK_QUEUE'))->run(
    $factory->pollTimeoutSeconds(),
);
