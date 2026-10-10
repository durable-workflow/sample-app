<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;

require __DIR__.'/vendor/autoload.php';

$phase = getenv('DURABLE_WORKFLOW_CANCELLATION_PHASE');
$client = new Client(
    getenv('DURABLE_WORKFLOW_SERVER_URL'),
    namespace: getenv('DURABLE_WORKFLOW_NAMESPACE'),
    token: getenv('DURABLE_WORKFLOW_AUTH_TOKEN') ?: null,
);
foreach (json_decode(getenv('DURABLE_WORKFLOW_CANCELLATION_RUNS'), true, flags: JSON_THROW_ON_ERROR) as $run) {
    if (($phase === 'duplicate' ? $run['child'] : $run['parent']) !== 'php') {
        continue;
    }
    $record = ['caller' => 'php', 'phase' => $phase,
        'workflow_id' => $run['parent_workflow_id'], 'run_id' => $run['parent_run_id']];
    try {
        $record['response'] = $client->requestWorkflowCancellation(
            $record['workflow_id'],
            $phase === 'duplicate' ? 'duplicate must not replace original' : 'SDK child cancellation conformance',
            $phase === 'duplicate' ? 60 : 30,
            $record['run_id'],
        );
        if (str_starts_with($phase, 'deny-')) {
            throw new RuntimeException('An unauthorized cancellation succeeded.');
        }
    } catch (ServerException $error) {
        $expected = $phase === 'deny-worker' ? 403 : ($phase === 'deny-anonymous' ? 401 : null);
        if ($error->status !== $expected) {
            throw $error;
        }
        $record['refusal'] = ['status' => $error->status, 'reason' => $error->reason];
    }
    echo json_encode($record, JSON_THROW_ON_ERROR)."\n";
}
