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
];
