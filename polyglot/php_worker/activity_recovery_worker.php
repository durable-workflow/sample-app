<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

function recoveryGate(string $caseId, string $suffix): void
{
    $path = getenv('ACTIVITY_RECOVERY_PROOF').'/'.$caseId.$suffix;
    $end = microtime(true) + 180;
    while (! is_file($path)) {
        if (microtime(true) >= $end) {
            throw new RuntimeException('Fixture gate was not released.');
        }
        usleep(100000);
    }
}

$mode = (string) getenv('ACTIVITY_RECOVERY_MODE');
$queue = 'activity-recovery-'.$mode.'-php';
$client = new Client((string) getenv('DURABLE_WORKFLOW_SERVER_URL'),
    token: (string) getenv('DURABLE_WORKFLOW_AUTH_TOKEN'), namespace: 'default');
$worker = new Worker($client, $queue, $queue.'-'.getenv('HOSTNAME'));
if ($mode === 'workflow') {
    $worker->registerWorkflow('sample-app.activity-recovery.php',
        static function (WorkflowContext $context, array $request): array {
            $runtime = $request['activity_runtime'];
            $result = $context->activity('sample-app.activity-recovery.'.$runtime.'.work', [$request], [
                'queue' => 'activity-recovery-activity-'.$runtime,
                'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [2]],
                'start_to_close_timeout' => $request['scenario'] === 'total-deadline' ? 60 : 20,
                'schedule_to_close_timeout' => $request['scenario'] === 'total-deadline' ? 30 : 120,
            ]);
            return ['workflow_runtime' => 'php', 'activity' => $result];
        });
} elseif ($mode === 'activity') {
    $worker->registerActivity('sample-app.activity-recovery.php.work',
        static function (ActivityContext $context, array $request): array {
            $receipt = ['case_id' => $request['case_id'], 'runtime' => 'php',
                'sdk_version' => 'durable-workflow-php/'.InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
                'pid' => getmypid(), 'task_id' => $context->taskId,
                'activity_attempt_id' => $context->activityAttemptId,
                'lease_owner' => $context->leaseOwner, 'attempt_number' => $context->attemptNumber];
            fwrite(STDOUT, json_encode(['event' => 'activity-started', 'claim' => $receipt], JSON_THROW_ON_ERROR)."\n");
            $path = getenv('ACTIVITY_RECOVERY_PROOF').'/'.$request['case_id'].'.attempt-'.$context->attemptNumber.'.json';
            file_put_contents($path.'.pending', json_encode($receipt, JSON_THROW_ON_ERROR));
            rename($path.'.pending', $path);
            if ($context->attemptNumber === 1) {
                recoveryGate($request['case_id'], '.first-release');
                throw new RuntimeException('injected first-attempt failure');
            }
            recoveryGate($request['case_id'], '.release');
            if ($request['scenario'] === 'retry-exhaustion') {
                throw new RuntimeException('injected second-attempt failure');
            }
            return $receipt;
        });
} else {
    throw new InvalidArgumentException('Unknown activity recovery worker mode.');
}
$worker->run(2);
