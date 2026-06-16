<?php

return [
    'enabled' => env('PROVADO_ENABLED', true),

    'observability' => [
        // When enabled, pipeline progress is logged through Laravel's logger
        // via PsrLoggerObserver; otherwise a NullPipelineObserver is used.
        'logging' => env('PROVADO_LOGGING_ENABLED', false),
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
