<?php

return [
    'enabled' => env('PROVADO_ENABLED', true),

    'observability' => [
        // When enabled, pipeline progress is logged through Laravel's logger
        // via PsrLoggerObserver; otherwise a NullPipelineObserver is used.
        'logging' => env('PROVADO_LOGGING_ENABLED', false),
    ],

    'storage' => [
        // Where canonical signals are stored per run: 'memory' (default,
        // ephemeral) or 'database' (persisted via the configured connection).
        'driver' => env('PROVADO_STORAGE_DRIVER', 'memory'),

        // Database connection name for the 'database' driver (null = default).
        'connection' => env('PROVADO_STORAGE_CONNECTION'),

        // Table backing the database driver; see the package migration.
        'table' => env('PROVADO_STORAGE_TABLE', 'provado_signals'),
    ],

    'sources' => [
        'new_relic' => [
            'enabled' => env('PROVADO_NEW_RELIC_ENABLED', false),
            'options' => [
                'account_id' => env('PROVADO_NEW_RELIC_ACCOUNT_ID'),
            ],
            'credentials' => [
                'api_key' => env('PROVADO_NEW_RELIC_API_KEY'),
            ],
        ],

        'adobe_commerce' => [
            'enabled' => env('PROVADO_ADOBE_COMMERCE_ENABLED', false),
            'options' => [
                'base_url' => env('PROVADO_ADOBE_COMMERCE_BASE_URL'),
            ],
            'credentials' => [
                'access_token' => env('PROVADO_ADOBE_COMMERCE_ACCESS_TOKEN'),
            ],
        ],
    ],
];
