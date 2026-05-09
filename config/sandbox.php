<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Sandbox Provider
    |--------------------------------------------------------------------------
    |
    | The orchestration sample resolves SandboxProvider through SandboxManager
    | by this name. Switching providers is a config edit; workflow code never
    | references a concrete provider class. Set SANDBOX_DRIVER=e2b in .env to
    | run the sample against the E2B Cloud HTTP API; leave it as 'local' to
    | run a subprocess-backed sandbox on the worker host (no API key needed).
    |
    */

    'default' => env('SANDBOX_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Provider Drivers
    |--------------------------------------------------------------------------
    |
    | Each driver entry is the constructor configuration for a SandboxProvider.
    | Keep entries even when unused — the test suite exercises both providers
    | so configuration drift is caught in CI rather than at provision time.
    |
    */

    'drivers' => [
        'local' => [
            'workspace_root' => env('SANDBOX_LOCAL_WORKSPACE_ROOT', storage_path('sandbox/workspaces')),
            'snapshot_root' => env('SANDBOX_LOCAL_SNAPSHOT_ROOT', storage_path('sandbox/snapshots')),
        ],

        'e2b' => [
            'api_key' => env('E2B_API_KEY', ''),
            'template' => env('E2B_TEMPLATE', 'base'),
            'base_url' => env('E2B_BASE_URL', 'https://api.e2b.dev'),
            'timeout_seconds' => (int) env('E2B_TIMEOUT_SECONDS', 300),
        ],
    ],
];
