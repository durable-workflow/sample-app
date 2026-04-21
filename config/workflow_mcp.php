<?php

declare(strict_types=1);
use App\Workflows\Ai\AiWorkflow;
use App\Workflows\Elapsed\ElapsedTimeWorkflow;
use App\Workflows\Microservice\MicroserviceWorkflow;
use App\Workflows\Playwright\CheckConsoleErrorsWorkflow;
use App\Workflows\Prism\PrismWorkflow;
use App\Workflows\Simple\SimpleWorkflow;
use App\Workflows\Webhooks\WebhookWorkflow;

return [
    /*
    |--------------------------------------------------------------------------
    | Workflow MCP Mapping
    |--------------------------------------------------------------------------
    |
    | This configuration file defines the mapping between workflow aliases
    | and their fully qualified class names. This provides a safer way to
    | expose workflows via MCP without allowing arbitrary class execution.
    |
    | Set 'allow_fqcn' to true to allow direct FQCN usage (less secure).
    |
    */

    'allow_fqcn' => env('WORKFLOW_MCP_ALLOW_FQCN', false),

    'workflows' => [
        'simple' => [
            'class' => SimpleWorkflow::class,
            'description' => 'Small deterministic workflow that runs two local activities and returns a string.',
            'pattern' => 'deterministic activity chain',
            'command' => 'php artisan app:workflow',
            'requires' => [],
            'arguments' => [],
        ],
        'elapsed' => [
            'class' => ElapsedTimeWorkflow::class,
            'description' => 'Timer/activity example that measures elapsed durable workflow time.',
            'pattern' => 'durable time and side effects',
            'command' => 'php artisan app:elapsed',
            'requires' => [],
            'arguments' => [],
        ],
        'microservice' => [
            'class' => MicroserviceWorkflow::class,
            'description' => 'Cross-application workflow using a shared database and queue.',
            'pattern' => 'multi-service coordination',
            'command' => 'php artisan app:microservice',
            'requires' => [],
            'arguments' => [],
        ],
        'playwright' => [
            'class' => CheckConsoleErrorsWorkflow::class,
            'description' => 'Browser automation workflow that records a page run and converts video output.',
            'pattern' => 'external automation and generated artifacts',
            'command' => 'php artisan app:playwright',
            'requires' => ['node', 'playwright', 'ffmpeg'],
            'arguments' => [
                ['name' => 'url', 'type' => 'string', 'default' => 'https://example.com'],
            ],
        ],
        'webhook' => [
            'class' => WebhookWorkflow::class,
            'description' => 'Webhook-started workflow that waits for an explicit ready signal before running an activity.',
            'pattern' => 'webhook start plus pull-style signal',
            'command' => 'php artisan app:webhook',
            'requires' => ['APP_URL reachable by the command'],
            'arguments' => [
                ['name' => 'message', 'type' => 'string', 'default' => 'world'],
            ],
            'signals' => [
                ['name' => 'ready', 'arguments' => []],
            ],
        ],
        'prism' => [
            'class' => PrismWorkflow::class,
            'description' => 'Durable AI workflow that generates and validates a profile through Prism.',
            'pattern' => 'AI activity loop with validation retry',
            'command' => 'php artisan app:prism',
            'requires' => ['OPENAI_API_KEY'],
            'arguments' => [],
        ],
        'ai' => [
            'class' => AiWorkflow::class,
            'description' => 'Interactive travel-agent workflow with pull-style signals, updates, outbox, and compensation.',
            'pattern' => 'signal-driven AI agent with saga compensation',
            'command' => 'php artisan app:ai',
            'requires' => ['OPENAI_API_KEY'],
            'arguments' => [
                ['name' => 'injectFailure', 'type' => 'string|null', 'allowed' => ['hotel', 'flight', 'car']],
            ],
            'signals' => [
                ['name' => 'send', 'arguments' => [['name' => 'message', 'type' => 'string']]],
            ],
            'updates' => [
                ['name' => 'receive', 'description' => 'Reads the next unsent assistant message from the workflow outbox.'],
            ],
        ],
    ],
];
