<?php

declare(strict_types=1);

use DurableWorkflow\WorkflowClientInterface;
use SampleAppPlayground\Scenario;

[$app] = require __DIR__.'/bootstrap.php';
$client = $app->make(WorkflowClientInterface::class);
$prefix = trim((string) getenv('SAMPLE_APP_PLAYGROUND_WORKFLOW_ID_PREFIX'));
if ($prefix === '') {
    throw new RuntimeException('Set SAMPLE_APP_PLAYGROUND_WORKFLOW_ID_PREFIX before starting the client.');
}

$workflowId = $prefix.'-'.bin2hex(random_bytes(8));
$handle = $client->startWorkflow(
    Scenario::WORKFLOW_TYPE,
    $workflowId,
    (string) getenv('DURABLE_WORKFLOW_TASK_QUEUE'),
);
$result = $handle->result(90.0, 0.25);
$runId = property_exists($handle, 'selectedRunId') ? $handle->selectedRunId : null;

echo json_encode([
    'workflow_id' => $workflowId,
    'run_id' => is_string($runId) && $runId !== '' ? $runId : null,
    'result' => $result,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
