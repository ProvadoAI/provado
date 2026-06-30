<?php

return [
    'enabled' => env('PROVADO_ENABLED', true),

    'observability' => [
        // When enabled, pipeline progress is logged through Laravel's logger
        // via PsrLoggerObserver; otherwise a NullPipelineObserver is used.
        'logging' => env('PROVADO_LOGGING_ENABLED', false),
    ],

    'http' => [
        // Default timeouts (seconds) applied to outbound source-client requests
        // by the default HttpClient. No outbound call is made until a source
        // adapter actually invokes the client; configuring these enables no
        // real source on its own.
        'timeout' => env('PROVADO_HTTP_TIMEOUT', 10),
        'connect_timeout' => env('PROVADO_HTTP_CONNECT_TIMEOUT', 5),
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
                // 'transaction_health' (default, APM transactions) or
                // 'operational_signals' (reads ProvadoSignal custom events).
                'mode' => env('PROVADO_NEW_RELIC_MODE', 'transaction_health'),
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
            // Magento integrations authenticate via OAuth 1.0a — all four
            // credentials are required to sign requests (a plain Bearer token is
            // not honored for the integration's ACL).
            'credentials' => [
                'consumer_key' => env('PROVADO_ADOBE_COMMERCE_CONSUMER_KEY'),
                'consumer_secret' => env('PROVADO_ADOBE_COMMERCE_CONSUMER_SECRET'),
                'access_token' => env('PROVADO_ADOBE_COMMERCE_ACCESS_TOKEN'),
                'access_token_secret' => env('PROVADO_ADOBE_COMMERCE_ACCESS_TOKEN_SECRET'),
            ],
        ],
    ],
];
