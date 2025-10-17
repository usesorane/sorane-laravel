<?php

declare(strict_types=1);

return [
    'enabled' => env('SORANE_ENABLED', false),
    'key' => env('SORANE_KEY'),

    'errors' => [
        'enabled' => env('SORANE_ERRORS_ENABLED', true),
        'queue' => env('SORANE_ERRORS_QUEUE', true),
        'queue_name' => env('SORANE_ERRORS_QUEUE_NAME', 'default'),
        'timeout' => env('SORANE_ERRORS_TIMEOUT', 10),
    ],

    'events' => [
        'enabled' => env('SORANE_EVENTS_ENABLED', true),
        'queue' => env('SORANE_EVENTS_QUEUE', true),
        'queue_name' => env('SORANE_EVENTS_QUEUE_NAME', 'default'),
        'timeout' => env('SORANE_EVENTS_TIMEOUT', 10),
    ],

    'logging' => [
        'enabled' => env('SORANE_LOGGING_ENABLED', false),
        'queue' => env('SORANE_LOGGING_QUEUE', true),
        'queue_name' => env('SORANE_LOGGING_QUEUE_NAME', 'default'),
        'timeout' => env('SORANE_LOGGING_TIMEOUT', 10),
        'excluded_channels' => [
            // Add channels here that should never be sent to Sorane
        ],
    ],

    'website_analytics' => [
        'enabled' => env('SORANE_WEBSITE_ANALYTICS_ENABLED', false),
        'queue' => env('SORANE_WEBSITE_ANALYTICS_QUEUE', true),
        'queue_name' => env('SORANE_WEBSITE_ANALYTICS_QUEUE_NAME', 'default'),
        'timeout' => env('SORANE_WEBSITE_ANALYTICS_TIMEOUT', 10),
        'excluded_paths' => [
            'horizon',
            'nova',
            'telescope',
            'admin',
            'filament',
            'api',
            'debugbar',
            'storage',
            'livewire',
            '_debugbar',
        ],
        'request_filter' => null,
        'user_agent' => [
            'min_length' => env('SORANE_WEBSITE_ANALYTICS_UA_MIN_LENGTH', 10),
            'max_length' => env('SORANE_WEBSITE_ANALYTICS_UA_MAX_LENGTH', 1000),
        ],
        'throttle_seconds' => env('SORANE_WEBSITE_ANALYTICS_THROTTLE_SECONDS', 30),
        'debug' => [
            'preserve_user_agent' => env('SORANE_WEBSITE_ANALYTICS_DEBUG_PRESERVE_UA', false),
        ],
    ],

    'javascript_errors' => [
        'enabled' => env('SORANE_JAVASCRIPT_ERRORS_ENABLED', false),
        'queue' => env('SORANE_JAVASCRIPT_ERRORS_QUEUE', true),
        'queue_name' => env('SORANE_JAVASCRIPT_ERRORS_QUEUE_NAME', 'default'),
        'timeout' => env('SORANE_JAVASCRIPT_ERRORS_TIMEOUT', 10),
        'sample_rate' => env('SORANE_JAVASCRIPT_ERRORS_SAMPLE_RATE', 1.0), // 1.0 = 100%, 0.1 = 10%
        'ignored_errors' => [
            // Browser quirks and unfixable issues
            'ResizeObserver loop limit exceeded',
            'ResizeObserver loop completed with undelivered notifications',

            // Cross-origin errors (no useful information due to CORS)
            'Script error.',
            'Script error',

            // Network errors (usually user connection issues, not bugs)
            'Failed to fetch',
            'NetworkError when attempting to fetch resource',
            'Network request failed',
            'Load failed',

            // Webpack/Vite chunk loading (usually navigation/stale deployments)
            'Loading chunk',
            'ChunkLoadError',

            // User-cancelled operations
            'cancelled',
            'canceled',
            'The operation was aborted',
            'AbortError',

            // Browser extension interference
            'Illegal invocation',

            // Add your own patterns here as needed
        ],
        'capture_console_errors' => env('SORANE_JAVASCRIPT_CAPTURE_CONSOLE_ERRORS', false),
        'max_breadcrumbs' => env('SORANE_JAVASCRIPT_MAX_BREADCRUMBS', 20),
    ],

    'batch' => [
        'queue_name' => env('SORANE_BATCH_QUEUE_NAME', 'default'),
        'cache_driver' => env('SORANE_BATCH_CACHE_DRIVER', 'redis'),
        'buffer_ttl' => env('SORANE_BATCH_BUFFER_TTL', 3600), // 1 hour
        'max_buffer_size' => env('SORANE_BATCH_MAX_BUFFER_SIZE', 5000),
        'max_batch_size' => env('SORANE_BATCH_MAX_BATCH_SIZE', 1000),
    ],
];
