<?php

return [
    'key' => env('SORANE_KEY'),
    'events' => [
        'enabled' => env('SORANE_EVENTS_ENABLED', true),
        'queue' => env('SORANE_EVENTS_QUEUE', true),
        'queue_name' => env('SORANE_EVENTS_QUEUE_NAME', 'default'),
    ],
    'logging' => [
        'enabled' => env('SORANE_LOGGING_ENABLED', false),
        'queue' => env('SORANE_LOGGING_QUEUE', true),
        'queue_name' => env('SORANE_LOGGING_QUEUE_NAME', 'default'),
        'excluded_channels' => [
            // Add channels here that should never be sent to Sorane
            // Note: The handler uses 'single' channel for its own error logging to prevent loops
        ],
    ],
    'website_analytics' => [
        'enabled' => env('SORANE_WEBSITE_ANALYTICS_ENABLED', false),
        'queue' => env('SORANE_WEBSITE_ANALYTICS_QUEUE', 'default'),
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
    ],
    'javascript_errors' => [
        'enabled' => env('SORANE_JAVASCRIPT_ERRORS_ENABLED', false),
        'queue' => env('SORANE_JAVASCRIPT_ERRORS_QUEUE', true),
        'queue_name' => env('SORANE_JAVASCRIPT_ERRORS_QUEUE_NAME', 'default'),
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
];
