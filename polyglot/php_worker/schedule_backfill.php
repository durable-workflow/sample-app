<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Model\ScheduleAction;
use DurableWorkflow\Model\ScheduleSpec;

require_once __DIR__.'/vendor/autoload.php';

if (count($argv) !== 4) {
    throw new InvalidArgumentException('Pass the backfill phase, workflow runtime, and two-digit UTC hour.');
}
[$phase, $runtime, $hour] = array_slice($argv, 1);
if (! in_array($phase, ['initial', 'repeat', 'timeout', 'recover'], true) || preg_match('/^(?:[01]\d|2[0-3])$/D', $hour) !== 1) {
    throw new InvalidArgumentException('Pass the backfill phase, workflow runtime, and two-digit UTC hour.');
}
$queueVariable = match ($runtime) {
    'php' => 'POLYGLOT_PHP_TASK_QUEUE',
    'python' => 'POLYGLOT_PY_TASK_QUEUE',
    'rust' => 'POLYGLOT_RUST_TASK_QUEUE',
    default => throw new InvalidArgumentException('Choose php, python, or rust as the workflow runtime.'),
};
$id = "php-backfill-{$runtime}";
$client = new Client(
    (string) getenv('DURABLE_WORKFLOW_SERVER_URL'),
    namespace: (string) getenv('DURABLE_WORKFLOW_NAMESPACE'),
    controlToken: (string) getenv('DURABLE_WORKFLOW_AUTH_TOKEN'),
);
$waiting = $phase === 'timeout';
$workflow = $waiting ? 'signal-query' : 'greeter';
$request = $runtime === 'rust' ? $id : ['name' => $id, 'locale' => 'en'];
if ($waiting) {
    $request = ['workflow_runtime' => $runtime];
}
$action = new ScheduleAction(
    "polyglot.{$runtime}.{$workflow}",
    (string) getenv($queueVariable),
    [$request],
    executionTimeoutSeconds: $waiting ? 10 : 60,
);
$schedule = $phase === 'initial'
    ? $client->createSchedule(
        new ScheduleSpec(cronExpressions: ["0 {$hour} * * *"], timezone: 'UTC'),
        $action,
        scheduleId: $id,
        overlapPolicy: 'skip',
    )
    : $client->scheduleHandle($id);
if (in_array($phase, ['timeout', 'recover'], true)) {
    $schedule->update(['action' => $action->toArray($client->payloadCodec())]);
}
[$firstDay, $endDay] = match ($phase) {
    'initial', 'repeat' => ['01', '03'],
    'timeout' => ['03', '04'],
    'recover' => ['03', '05'],
};
$result = $schedule->backfill("2020-01-{$firstDay}T{$hour}:00:00Z", "2020-01-{$endDay}T{$hour}:00:00Z");
echo json_encode($result + [
    'phase' => $phase,
    'schedule_creator' => 'php',
    'workflow_runtime' => $runtime,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
