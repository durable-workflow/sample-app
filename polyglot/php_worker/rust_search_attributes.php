<?php

declare(strict_types=1);

use DurableWorkflow\Client;

require __DIR__.'/vendor/autoload.php';

$phase = $argv[1] ?? '';
if (! in_array($phase, ['park', 'verify'], true)) {
    throw new InvalidArgumentException('Use park or verify.');
}
$proof = (string) getenv('SEARCH_ATTRIBUTES_PROOF');
$observation = json_decode(file_get_contents("{$proof}/{$phase}.json"), true, flags: JSON_THROW_ON_ERROR);
$client = new Client(
    (string) getenv('DURABLE_WORKFLOW_SERVER_URL'),
    namespace: (string) getenv('DURABLE_WORKFLOW_NAMESPACE'),
    controlToken: (string) getenv('DURABLE_WORKFLOW_AUTH_TOKEN'),
);

function assertSearchValues(?array $actual, array $expected): void
{
    if ($actual === null) {
        throw new RuntimeException('PHP SDK did not return search attributes.');
    }
    ksort($actual);
    ksort($expected);
    if (array_keys($actual) !== array_keys($expected)) {
        throw new RuntimeException('PHP SDK search attribute keys differ.');
    }
    foreach ($expected as $key => $value) {
        if ($key === 'SearchTime') {
            if ((new DateTimeImmutable($actual[$key]))->format('U.u') !== (new DateTimeImmutable($value))->format('U.u')) {
                throw new RuntimeException('PHP SDK lost datetime precision.');
            }
        } elseif ($actual[$key] !== $value) {
            throw new RuntimeException("PHP SDK lost the type or value of {$key}.");
        }
    }
}

$schema = json_decode(file_get_contents("{$proof}/setup.json"), true, flags: JSON_THROW_ON_ERROR);
$definitions = $client->listSearchAttributes()->customAttributes;
ksort($definitions);
ksort($schema['definitions']);
if ($definitions !== $schema['definitions']) {
    throw new RuntimeException('PHP SDK changed the canonical schema.');
}
$description = $client->describeWorkflow($observation['workflow_id']);
if ($description->runId !== $observation['run_id'] || $description->status !== ($phase === 'park' ? 'waiting' : 'completed')) {
    throw new RuntimeException('PHP SDK changed the original run or status.');
}
assertSearchValues($description->searchAttributes, $observation['attributes']);
$queries = [];
foreach ($observation['queries'] as $query => $expectedIds) {
    $page = $client->listWorkflows(workflowType: 'sample-app.rust.search-attributes', query: $query);
    $ids = array_map(static fn ($execution): string => $execution->workflowId, $page->executions);
    if ($ids !== $expectedIds) {
        throw new RuntimeException("PHP SDK visibility differs for {$query}.");
    }
    foreach ($page->executions as $execution) {
        assertSearchValues($execution->searchAttributes, $observation['attributes']);
    }
    $queries[$query] = $ids;
}
$history = $client->workflowHistory($description->workflowId, $description->runId);
if ($history !== $observation['history']) {
    throw new RuntimeException('PHP and Python returned different original-run histories.');
}
$result = ['observer' => 'php', 'phase' => $phase, 'workflow_id' => $description->workflowId,
    'run_id' => $description->runId, 'attributes' => $description->searchAttributes, 'queries' => $queries];
file_put_contents("{$proof}/php-{$phase}.json", json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
