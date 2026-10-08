<?php

declare(strict_types=1);

use DurableWorkflow\Attribute\Signal;
use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Exception\ChildWorkflowFailed;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

function phpChildQueue(string $runtime): string
{
    $queue = getenv('DURABLE_WORKFLOW_TASK_QUEUE');

    return is_string($queue) && $queue !== '' ? $queue : 'polyglot-'.$runtime;
}

function phpChildResult(WorkflowContext $context, string $runtime, string $value, string $behavior): array
{
    try {
        $result = $context->childWorkflow('sample-app.child-matrix.'.$runtime.'.child', [$value, $behavior], [
            'queue' => phpChildQueue($runtime),
        ]);

        return ['parent_runtime' => 'php', 'child_result' => $result];
    } catch (ChildWorkflowFailed $failure) {
        return ['parent_runtime' => 'php', 'child_failure' => [
            'type' => 'ChildWorkflowFailed',
            'message' => $failure->getMessage(),
            'child_type' => $failure->workflowType,
        ]];
    }
}

final class PhpChildWorkflow
{
    #[Workflow('sample-app.child-matrix.php.child')]
    public function run(WorkflowContext $context, string $value, string $behavior = 'complete'): array
    {
        if ($behavior === 'fail') {
            throw new RuntimeException('child-probe-failure: '.$value);
        }
        if ($behavior === 'wait') {
            $context->waitSignal('child-finish');
        } elseif ($behavior !== 'complete') {
            throw new InvalidArgumentException('Unknown child behavior.');
        }

        return ['value' => $value, 'runtime' => 'php'];
    }

    #[Signal('child-finish')]
    public function finish(): void {}
}

final class PhpParentPhpWorkflow
{
    #[Workflow('sample-app.child-matrix.php.parent-php')]
    public function run(WorkflowContext $context, string $value, string $behavior = 'complete'): array
    {
        return phpChildResult($context, 'php', $value, $behavior);
    }
}

final class PhpParentPythonWorkflow
{
    #[Workflow('sample-app.child-matrix.php.parent-python')]
    public function run(WorkflowContext $context, string $value, string $behavior = 'complete'): array
    {
        return phpChildResult($context, 'python', $value, $behavior);
    }
}

final class PhpParentRustWorkflow
{
    #[Workflow('sample-app.child-matrix.php.parent-rust')]
    public function run(WorkflowContext $context, string $value, string $behavior = 'complete'): array
    {
        return phpChildResult($context, 'rust', $value, $behavior);
    }
}

function configureChildWorkflows(Worker $worker): void
{
    $worker->register(PhpChildWorkflow::class, PhpParentPhpWorkflow::class,
        PhpParentPythonWorkflow::class, PhpParentRustWorkflow::class);
}
