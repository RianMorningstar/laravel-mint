<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Generation Settings
    |--------------------------------------------------------------------------
    |
    | These settings control the default behavior of data generation including
    | chunk sizes, memory limits, and performance optimization settings.
    |
    */

    'generation' => [
        'chunk_size' => env('MINT_CHUNK_SIZE', 1000),
        'memory_limit' => env('MINT_MEMORY_LIMIT', '512M'),
        'timeout' => env('MINT_TIMEOUT', 600), // seconds
        'use_transactions' => env('MINT_USE_TRANSACTIONS', true),
        'parallel_workers' => env('MINT_PARALLEL_WORKERS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pattern Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the patterns used for generating realistic data including
    | statistical distributions and temporal patterns.
    |
    */

    'patterns' => [
        'path' => base_path('patterns'),
        'cache' => env('MINT_CACHE_PATTERNS', true),
        'defaults' => [
            'distribution' => 'normal',
            'temporal' => 'linear',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scenario Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for pre-built scenarios and custom scenario definitions.
    |
    */

    'scenarios' => [
        'default' => env('MINT_DEFAULT_SCENARIO', 'simple'),
        'path' => base_path('scenarios'),
        'auto_discover' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automatic model analysis and relationship detection.
    |
    */

    'analysis' => [
        'cache_results' => env('MINT_CACHE_ANALYSIS', true),
        'cache_duration' => 3600, // seconds
        'ignore_models' => [],
        'max_depth' => 10, // for relationship traversal
        'detect_validations' => true,
        'detect_scopes' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Settings
    |--------------------------------------------------------------------------
    |
    | Database-specific configuration for optimized data generation.
    |
    */

    'database' => [
        'connection' => env('MINT_DB_CONNECTION', config('database.default')),
        'use_insert_ignore' => false,
        'foreign_key_checks' => true,
        'truncate_before_generate' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anonymization Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for data anonymization when importing production data.
    |
    */

    'anonymization' => [
        'enabled' => env('MINT_ANONYMIZATION_ENABLED', true),
        'compliance_level' => env('MINT_COMPLIANCE_LEVEL', 'standard'), // standard, gdpr, hipaa, pci
        'preserve_statistics' => true,
        'hash_seed' => env('MINT_HASH_SEED', 'mint-default-seed'),

        'sensitive_fields' => [
            'email',
            'phone',
            'ssn',
            'credit_card',
            'password',
            'api_key',
            'secret',
            'token',
        ],

        'strategies' => [
            'email' => 'email_hash',
            'phone' => 'phone_mask',
            'name' => 'name_faker',
            'address' => 'address_faker',
            'credit_card' => 'card_mask',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Settings for monitoring and reporting generation performance.
    |
    */

    'monitoring' => [
        'enabled' => env('MINT_MONITORING_ENABLED', false),
        'log_channel' => env('MINT_LOG_CHANNEL', 'daily'),
        'track_memory' => true,
        'track_queries' => true,
        'slow_query_threshold' => 1000, // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Settings
    |--------------------------------------------------------------------------
    |
    | Settings specific to development and testing environments.
    |
    */

    'development' => [
        'seed' => env('MINT_SEED', null),
        'debug' => env('MINT_DEBUG', false),
        'strict_mode' => env('MINT_STRICT', false),
        'validate_output' => env('MINT_VALIDATE', true),
    ],
];
