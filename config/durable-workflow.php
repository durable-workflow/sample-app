<?php

declare(strict_types=1);

use App\Workflows\ServiceMode\PrepareWelcomeActivity;
use App\Workflows\ServiceMode\WelcomeWorkflow;

return [
    'runtime_url' => env('DURABLE_WORKFLOW_RUNTIME_URL', 'http://localhost:8080'),
    'namespace' => env('DURABLE_WORKFLOW_NAMESPACE', 'default'),
    'task_queue' => env('DURABLE_WORKFLOW_TASK_QUEUE', WelcomeWorkflow::PHP_TASK_QUEUE),

    // Credentials remain process environment values so config caches never contain secrets.
    'handlers' => [
        WelcomeWorkflow::class,
        PrepareWelcomeActivity::class,
    ],

    'poll_timeout_seconds' => 5,
];
