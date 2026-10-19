<?php

declare(strict_types=1);

require_once __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

$revision = getenv('POLYGLOT_TIMER_APPLICATION');
if (! in_array($revision, ['v1', 'v2'], true)) {
    throw new InvalidArgumentException('Select activity application v1 or v2.');
}
$client = new Client((string) getenv('DURABLE_WORKFLOW_SERVER_URL'),
    token: (string) getenv('DURABLE_WORKFLOW_AUTH_TOKEN'), namespace: 'default');
$worker = new Worker($client, 'polyglot-php', 'activity-upgrade-php-'.getenv('HOSTNAME'));
$worker->registerWorkflow('polyglot.php.activity-upgrade',
    static function (WorkflowContext $context, array $request) use ($revision): array {
        $version = $context->getVersion('sample-activity-application', 1, $revision === 'v1' ? 1 : 2);
        $result = $context->activity('polyglot.'.$request['activity_runtime'].'.upgrade-activity',
            [['case_id' => $request['case_id'], 'version' => $version, 'value' => $request['value']]],
            ['queue' => 'polyglot-'.$request['activity_runtime'],
                'retry_policy' => ['max_attempts' => $version + 1, 'backoff_seconds' => $version === 1 ? [1] : [1, 2]],
                'start_to_close_timeout' => 30, 'schedule_to_close_timeout' => $version === 1 ? 120 : 180]);

        return ['workflow_runtime' => 'php', 'application_revision' => $revision,
            'version' => $version, 'activity' => $result];
    });
// Previous workers deliberately author pending work without an activity handler.
if ($revision === 'v2') {
    $worker->registerActivity('polyglot.php.upgrade-activity',
        static function (ActivityContext $context, array $request) use ($revision): array {
            return ['activity_runtime' => 'php', 'application_revision' => $revision,
                'sdk_version' => 'durable-workflow-php/'.InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
                'attempt_number' => $context->attemptNumber, 'request' => $request];
        });
}
$worker->run(2);
