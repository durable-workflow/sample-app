<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

function required(string $name): string
{
    $value = getenv($name);
    if (! is_string($value) || trim($value) === '') {
        throw new RuntimeException("Set {$name} before starting the worker.");
    }

    return $value;
}

final class PhpChildWorkflow
{
    #[Workflow('sample-app.child-matrix.php.child')]
    public function run(WorkflowContext $context, string $value): array
    {
        return ['value' => $value, 'runtime' => 'php'];
    }
}

final class PhpParentPhpWorkflow
{
    #[Workflow('sample-app.child-matrix.php.parent-php')]
    public function run(WorkflowContext $context, string $value): array
    {
        return [
            'parent_runtime' => 'php',
            'child_result' => $context->childWorkflow('sample-app.child-matrix.php.child', [$value]),
        ];
    }
}

final class PhpParentPythonWorkflow
{
    #[Workflow('sample-app.child-matrix.php.parent-python')]
    public function run(WorkflowContext $context, string $value): array
    {
        return [
            'parent_runtime' => 'php',
            'child_result' => $context->childWorkflow('sample-app.child-matrix.python.child', [$value]),
        ];
    }
}

final class PhpParentRustWorkflow
{
    #[Workflow('sample-app.child-matrix.php.parent-rust')]
    public function run(WorkflowContext $context, string $value): array
    {
        return [
            'parent_runtime' => 'php',
            'child_result' => $context->childWorkflow('sample-app.child-matrix.rust.child', [$value]),
        ];
    }
}

$client = new Client(
    required('DURABLE_WORKFLOW_RUNTIME_URL'),
    namespace: required('DURABLE_WORKFLOW_NAMESPACE'),
    workerToken: required('DURABLE_WORKFLOW_WORKER_TOKEN'),
);

Worker::create($client, required('DURABLE_WORKFLOW_TASK_QUEUE'))
    ->register(
        PhpChildWorkflow::class,
        PhpParentPhpWorkflow::class,
        PhpParentPythonWorkflow::class,
        PhpParentRustWorkflow::class,
    )
    ->run();
