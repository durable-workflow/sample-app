<?php

declare(strict_types=1);

use DurableWorkflow\Bridge\ServiceConfiguration;
use Illuminate\Contracts\Console\Kernel;
use SampleAppPlayground\PlaygroundActivity;
use SampleAppPlayground\PlaygroundWorkflow;

require_once __DIR__.'/Scenario.php';
require_once __DIR__.'/PlaygroundActivity.php';
require_once __DIR__.'/PlaygroundWorkflow.php';

$required = static function (string $name): string {
    $value = trim((string) getenv($name));
    if ($value === '') {
        throw new RuntimeException("Set {$name} before starting the PHP playground.");
    }

    return $value;
};

$scenario = json_decode(
    $required('SAMPLE_APP_PLAYGROUND_SCENARIO'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (! is_array($scenario) || ! is_array($scenario['expected_result'] ?? null)) {
    throw new RuntimeException('SAMPLE_APP_PLAYGROUND_SCENARIO is invalid.');
}

$root = $required('SAMPLE_APP_ROOT');
require_once $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = $app->make('config');
$config->set('durable-workflow.endpoint', $required('DURABLE_WORKFLOW_RUNTIME_URL'));
$config->set('durable-workflow.namespace', $required('DURABLE_WORKFLOW_NAMESPACE'));
$config->set('durable-workflow.task_queue', $required('DURABLE_WORKFLOW_TASK_QUEUE'));
$config->set('durable-workflow.handlers', [PlaygroundWorkflow::class, PlaygroundActivity::class]);
$config->set('sample-app-playground.activity_result', $scenario['expected_result']);

// The bridge reads serializable Laravel configuration when these services are
// first resolved. Credentials remain role-specific process environment values.
$app->forgetInstance(ServiceConfiguration::class);

return [$app, $scenario];
