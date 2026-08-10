<?php

declare(strict_types=1);

return [
    'default' => env('DURABLE_AI_SANDBOX_DRIVER', 'local'),

    // This bounds provider resources while their lease reconciliation remains
    // active. Lifecycle operations that would remove a provider TTL stay
    // unavailable unless the provider has an independent cleanup guarantee.
    'lease_ttl_seconds' => (int) env('DURABLE_AI_SANDBOX_LEASE_TTL', 900),

    'drivers' => [
        // Development/test-only. This subprocess workspace runs with the
        // Laravel worker's privileges and is not a security isolation boundary.
        'local' => [
            'workspace_root' => env(
                'DURABLE_AI_LOCAL_WORKSPACE_ROOT',
                storage_path('sandbox/workspaces'),
            ),
            'snapshot_root' => env(
                'DURABLE_AI_LOCAL_SNAPSHOT_ROOT',
                storage_path('sandbox/snapshots'),
            ),
        ],

        'e2b' => [
            'api_key' => env('E2B_API_KEY', ''),
            'template_id' => env('E2B_TEMPLATE_ID', 'base'),
            'api_base_url' => env('E2B_API_BASE_URL', 'https://api.e2b.app'),
            'sandbox_base_url' => env('E2B_SANDBOX_BASE_URL', 'https://sandbox.e2b.app'),
            'timeout_seconds' => (int) env('E2B_REQUEST_TIMEOUT_SECONDS', 300),
        ],
    ],
];
