<?php

declare(strict_types=1);
use App\Workflows\Elapsed\ElapsedTimeWorkflow;
use App\Workflows\Prism\PrismWorkflow;
use App\Workflows\Simple\SimpleWorkflow;

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
            'requires' => [],
            'arguments' => [],
        ],
        'elapsed' => [
            'class' => ElapsedTimeWorkflow::class,
            'description' => 'Timer/activity example that measures elapsed durable workflow time.',
            'requires' => [],
            'arguments' => [],
        ],
        'prism' => [
            'class' => PrismWorkflow::class,
            'description' => 'Durable AI workflow that generates and validates a profile through Prism.',
            'requires' => ['OPENAI_API_KEY'],
            'arguments' => [],
        ],
        // Add more workflow mappings here as needed. A value may be a class string
        // or an array with class, description, requires, and arguments metadata.
    ],
];
