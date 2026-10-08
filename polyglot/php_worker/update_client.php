<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;

require __DIR__.'/vendor/autoload.php';

if ($argc !== 4 && $argc !== 5) {
    throw new InvalidArgumentException('Expected workflow ID, request ID, update name and optional increment delta.');
}
$client = new Client(
    (string) getenv('DURABLE_WORKFLOW_SERVER_URL'),
    token: (string) getenv('DURABLE_WORKFLOW_AUTH_TOKEN'),
    namespace: (string) getenv('DURABLE_WORKFLOW_NAMESPACE'),
);
$request = ['caller' => 'php', 'request_id' => $argv[2], 'value' => 'hello', 'nested' => ['enabled' => true, 'count' => 42]];
if ($argv[3] === 'increment') {
    $delta = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT);
    if ($delta === false) {
        throw new InvalidArgumentException('An increment requires an integer delta.');
    }
    $request['delta'] = $delta;
}
try {
    $result = $client->updateWorkflow($argv[1], $argv[3], [$request], requestId: $argv[2]);
} catch (ServerException $exception) {
    fwrite(STDERR, json_encode([
        'caller' => 'php', 'workflow_id' => $argv[1], 'request_id' => $argv[2],
        'error' => get_class($exception), 'status' => $exception->status,
        'reason' => $exception->reason, 'details' => $exception->details,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
fwrite(STDOUT, json_encode(['caller' => 'php', 'request_id' => $argv[2], 'result' => $result], JSON_THROW_ON_ERROR).PHP_EOL);
