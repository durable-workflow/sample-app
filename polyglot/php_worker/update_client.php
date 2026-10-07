<?php

declare(strict_types=1);

use DurableWorkflow\Client;

require __DIR__.'/vendor/autoload.php';

if ($argc !== 4) {
    throw new InvalidArgumentException('Expected workflow ID, request ID and update name.');
}
$client = new Client(
    (string) getenv('DURABLE_WORKFLOW_SERVER_URL'),
    token: (string) getenv('DURABLE_WORKFLOW_AUTH_TOKEN'),
    namespace: (string) getenv('DURABLE_WORKFLOW_NAMESPACE'),
);
$request = ['caller' => 'php', 'request_id' => $argv[2], 'value' => 'hello', 'nested' => ['enabled' => true, 'count' => 42]];
$result = $client->updateWorkflow($argv[1], $argv[3], [$request], requestId: $argv[2]);
fwrite(STDOUT, json_encode(['caller' => 'php', 'request_id' => $argv[2], 'result' => $result], JSON_THROW_ON_ERROR).PHP_EOL);
