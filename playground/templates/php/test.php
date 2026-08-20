<?php

declare(strict_types=1);

use DurableWorkflow\Bridge\Laravel\WorkerFactory;
use DurableWorkflow\Testing\WorkerTestHarness;
use DurableWorkflow\Testing\WorkflowClientFake;
use DurableWorkflow\WorkflowClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SampleAppPlayground\Scenario;

[$app, $scenario] = require __DIR__.'/bootstrap.php';
$expected = $scenario['expected_result'];
$app->instance(LoggerInterface::class, new NullLogger);
$worker = $app->make(WorkerFactory::class)->make((string) getenv('DURABLE_WORKFLOW_TASK_QUEUE'));
$harness = new WorkerTestHarness($worker);
$harness->assertActivityResult(Scenario::ACTIVITY_TYPE, [
    'greeting' => $expected['greeting'],
    'activity_runtime' => $expected['activity_runtime'],
]);

$workflowId = $scenario['workflow_id_prefix'].'-test';
$fake = (new WorkflowClientFake)->setWorkflowResult($workflowId, $expected);
$app->instance(WorkflowClientInterface::class, $fake);
$handle = $app->make(WorkflowClientInterface::class)->startWorkflow(
    Scenario::WORKFLOW_TYPE,
    $workflowId,
    (string) getenv('DURABLE_WORKFLOW_TASK_QUEUE'),
);
if ($handle->result() !== $expected) {
    throw new RuntimeException('The Laravel SDK fake returned an unexpected result.');
}
$fake->assertWorkflowStarted(Scenario::WORKFLOW_TYPE);
$fake->assertResultRequested($workflowId);

echo "Laravel bridge dependency injection, configuration, logging, and test fake: ready\n";
