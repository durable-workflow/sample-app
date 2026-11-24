<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Model\ScheduleAction;
use DurableWorkflow\Model\ScheduleSpec;

require __DIR__.'/vendor/autoload.php';

function requiredScheduleEnv(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value === '') {
        throw new RuntimeException("Set {$name} before running the schedule experiment.");
    }

    return $value;
}

$arguments = array_slice($argv, 1);
$keepSchedule = end($arguments) === '--keep-schedule';
if ($keepSchedule) {
    array_pop($arguments);
}
$runtime = $arguments[0] ?? 'rust';
$queueVariable = match ($runtime) {
    'php' => 'POLYGLOT_PHP_TASK_QUEUE',
    'python' => 'POLYGLOT_PY_TASK_QUEUE',
    'rust' => 'POLYGLOT_RUST_TASK_QUEUE',
    default => throw new InvalidArgumentException('Choose php, python, or rust as the workflow runtime.'),
};
if (count($arguments) > 1) {
    throw new InvalidArgumentException('Pass one optional workflow runtime followed by --keep-schedule.');
}

$marker = "php-schedule-{$runtime}-".bin2hex(random_bytes(6));
$request = $runtime === 'rust' ? $marker : ['name' => $marker, 'locale' => 'en'];
$client = new Client(
    requiredScheduleEnv('DURABLE_WORKFLOW_SERVER_URL'),
    namespace: requiredScheduleEnv('DURABLE_WORKFLOW_NAMESPACE'),
    controlToken: requiredScheduleEnv('DURABLE_WORKFLOW_AUTH_TOKEN'),
);
$schedule = $client->createSchedule(
    new ScheduleSpec(intervals: [['every' => 'PT10S']], timezone: 'UTC'),
    new ScheduleAction("polyglot.{$runtime}.greeter", requiredScheduleEnv($queueVariable), [$request]),
    scheduleId: $marker,
    maxRuns: $keepSchedule ? 12 : null,
);

$verified = false;
try {
    $deadline = microtime(true) + 120;
    do {
        $description = $schedule->describe();
        $workflowId = (string) ($description->raw['latest_workflow_instance_id'] ?? '');
        if ($description->firesCount >= 1 && $workflowId !== '') {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('The automatic schedule fire did not start a workflow.');
        }
        usleep(500_000);
    } while (true);

    $result = $client->workflowHandle($workflowId)->result(90, 0.5);
    if (! is_array($result) || ($result['workflow_runtime'] ?? null) !== $runtime) {
        throw new RuntimeException("Unexpected {$runtime} workflow result: ".var_export($result, true));
    }
    $observedRequest = $result['request'] ?? null;
    $requestMatches = $runtime === 'rust'
        ? $observedRequest === $marker
        : is_array($observedRequest)
            && count($observedRequest) === 2
            && ($observedRequest['name'] ?? null) === $marker
            && ($observedRequest['locale'] ?? null) === 'en';
    $activityMatches = match ($runtime) {
        'php' => ($result['activity_runtime'] ?? null) === 'php'
            && ($result['php_marker']['name'] ?? null) === $marker
            && ($result['php_description']['runtime'] ?? null) === 'php',
        'python' => ($result['greeting']['language'] ?? null) === 'python'
            && ($result['greeting']['greeting'] ?? null) === "hello, {$marker}"
            && ($result['summary']['language'] ?? null) === 'python',
        'rust' => ($result['activity_runtime'] ?? null) === 'rust'
            && ($result['echo']['runtime'] ?? null) === 'rust'
            && ($result['echo']['value'] ?? null) === $marker,
    };
    if (! $requestMatches || ! $activityMatches) {
        throw new RuntimeException("Unexpected {$runtime} workflow result: ".var_export($result, true));
    }

    $execution = $client->describeWorkflow($workflowId);
    if ($execution->runId === null || $execution->runId === '' || $execution->status !== 'completed') {
        throw new RuntimeException('Scheduled workflow did not complete.');
    }
    $workflowHistory = $client->workflowHistory($workflowId, $execution->runId);
    $workflowEvents = array_map(
        static fn (array $event): string => (string) ($event['event_type'] ?? ''),
        $workflowHistory['events'] ?? [],
    );
    foreach (['WorkflowStarted', 'ScheduleTriggered', 'ActivityScheduled', 'ActivityCompleted', 'WorkflowCompleted'] as $type) {
        if (! in_array($type, $workflowEvents, true)) {
            throw new RuntimeException("Missing {$type} in workflow history.");
        }
    }
    if (array_search('ActivityScheduled', $workflowEvents, true) >= array_search('ActivityCompleted', $workflowEvents, true)) {
        throw new RuntimeException('Activity history is out of order.');
    }

    $scheduleHistory = $schedule->history();
    $scheduleEvents = array_map(
        static fn (array $event): string => (string) ($event['event_type'] ?? ''),
        $scheduleHistory['events'] ?? [],
    );
    $linkedTrigger = array_any(
        $scheduleHistory['events'] ?? [],
        static fn (array $event): bool => ($event['event_type'] ?? null) === 'ScheduleTriggered'
            && ($event['workflow_instance_id'] ?? null) === $workflowId
            && ($event['workflow_run_id'] ?? null) === $execution->runId,
    );
    if (! $linkedTrigger) {
        throw new RuntimeException('Schedule history has no trigger linked to the completed run.');
    }

    echo json_encode([
        'schedule_creator' => 'php',
        'workflow_runtime' => $runtime,
        'schedule_id' => $marker,
        'workflow_id' => $workflowId,
        'run_id' => $execution->runId,
        'fires_count' => $description->firesCount,
        'schedule_events' => $scheduleEvents,
        'workflow_events' => $workflowEvents,
        'result' => $result,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    echo 'PHP-created schedule -> '.ucfirst($runtime).' worker completed'.PHP_EOL;
    $verified = true;
} finally {
    if (! $keepSchedule || ! $verified) {
        $schedule->delete();
    }
}
