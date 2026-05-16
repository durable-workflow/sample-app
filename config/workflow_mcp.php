<?php

declare(strict_types=1);
use App\Workflows\Ai\AiWorkflow;
use App\Workflows\Elapsed\ElapsedTimeWorkflow;
use App\Workflows\Microservice\MicroserviceWorkflow;
use App\Workflows\Playwright\CheckConsoleErrorsWorkflow;
use App\Workflows\Polyglot\PhpToPythonWorkflow;
use App\Workflows\Prism\PrismWorkflow;
use App\Workflows\Sandbox\SandboxAgentWorkflow;
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
            'description' => 'Interactive travel-agent workflow with pull-style signals, durable assistant message streams, and compensation.',
            'pattern' => 'signal-driven AI agent with durable inbox/outbox stream',
            'command' => 'php artisan app:ai',
            'requires' => ['OPENAI_API_KEY'],
            'arguments' => [
                ['name' => 'injectFailure', 'type' => 'string|null', 'allowed' => ['hotel', 'flight', 'car']],
            ],
            'signals' => [
                ['name' => 'send', 'arguments' => [['name' => 'message', 'type' => 'string']]],
            ],
            'updates' => [
                ['name' => 'receive', 'description' => 'Consumes the next assistant reply from the durable ai.assistant message stream.'],
            ],
        ],
        'sandbox' => [
            'class' => SandboxAgentWorkflow::class,
            'description' => 'Durable sandbox orchestration: provision, dispatch tool calls, snapshot, recover, and clean up against a swappable sandbox provider.',
            'pattern' => 'agent sandbox lifecycle (provision, dispatch, suspend/resume, snapshot/restore, cleanup)',
            'command' => 'php artisan app:sandbox',
            'requires' => ['SANDBOX_DRIVER (default local; set to e2b plus E2B_API_KEY for the E2B Cloud provider)'],
            'arguments' => [
                ['name' => 'toolCalls', 'type' => 'array', 'description' => 'Ordered list of {type, args} tool calls to dispatch through the sandbox.'],
                ['name' => 'provider', 'type' => 'string|null', 'description' => 'Provider override; defaults to config(sandbox.default).'],
                ['name' => 'snapshotEveryNCalls', 'type' => 'int', 'default' => 0],
                ['name' => 'suspendBetweenCalls', 'type' => 'bool', 'default' => false],
                ['name' => 'options', 'type' => 'array', 'default' => []],
            ],
        ],
        'polyglot_php_to_python' => [
            'class' => PhpToPythonWorkflow::class,
            'description' => 'PHP-authored workflow that schedules Python activities; the polyglot compose smoke also exercises Python-authored workflows.',
            'pattern' => 'cross-language activity dispatch',
            'command' => 'docker compose -f polyglot/docker-compose.yml run --rm smoke',
            'requires' => ['polyglot/ docker compose stack'],
            'arguments' => [
                ['name' => 'value', 'type' => 'string', 'default' => 'polyglot'],
            ],
        ],
    ],
];
