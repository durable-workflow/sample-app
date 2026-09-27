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

$marker = 'php-schedule-rust-'.bin2hex(random_bytes(6));
$client = new Client(
    requiredScheduleEnv('DURABLE_WORKFLOW_SERVER_URL'),
    namespace: requiredScheduleEnv('DURABLE_WORKFLOW_NAMESPACE'),
    controlToken: requiredScheduleEnv('DURABLE_WORKFLOW_AUTH_TOKEN'),
);
$schedule = $client->createSchedule(
    new ScheduleSpec(intervals: [['every' => 'PT10S']], timezone: 'UTC'),
    new ScheduleAction('polyglot.rust.greeter', requiredScheduleEnv('POLYGLOT_RUST_TASK_QUEUE'), [$marker]),
    scheduleId: $marker,
);

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
    if (
        ! is_array($result)
        || ($result['workflow_runtime'] ?? null) !== 'rust'
        || ($result['activity_runtime'] ?? null) !== 'rust'
        || ($result['request'] ?? null) !== $marker
        || ($result['echo']['runtime'] ?? null) !== 'rust'
        || ($result['echo']['value'] ?? null) !== $marker
    ) {
        throw new RuntimeException('Unexpected Rust workflow result: '.var_export($result, true));
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
        throw new RuntimeException('Schedule history has no trigger linked to the completed Rust run.');
    }

    echo json_encode([
        'schedule_creator' => 'php',
        'workflow_runtime' => 'rust',
        'schedule_id' => $marker,
        'workflow_id' => $workflowId,
        'run_id' => $execution->runId,
        'fires_count' => $description->firesCount,
        'schedule_events' => $scheduleEvents,
        'workflow_events' => $workflowEvents,
        'result' => $result,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    echo 'PHP-created schedule -> Rust worker completed'.PHP_EOL;
} finally {
    $schedule->delete();
}
