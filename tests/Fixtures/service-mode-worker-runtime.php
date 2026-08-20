<?php

declare(strict_types=1);

$requestLog = getenv('SERVICE_MODE_WORKER_REQUEST_LOG');
if (! is_string($requestLog) || $requestLog === '') {
    http_response_code(500);

    return;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$headers = getallheaders();
$body = json_decode((string) file_get_contents('php://input'), true);
file_put_contents($requestLog, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'path' => $path,
    'authorization' => $headers['Authorization'] ?? $headers['authorization'] ?? null,
    'body' => is_array($body) ? $body : null,
], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST' && $path === '/api/worker/register') {
    echo json_encode(['registered' => true], JSON_THROW_ON_ERROR);

    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST' && $path === '/api/worker/workflow-tasks/poll') {
    echo json_encode([
        'task' => null,
        'poll_status' => 'stopped',
        'reason' => 'worker_stopped',
    ], JSON_THROW_ON_ERROR);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'unexpected_test_request'], JSON_THROW_ON_ERROR);
