<?php

return [
    'key' => env('SORANE_KEY'),
    'events' => [
        'enabled' => env('SORANE_EVENTS_ENABLED', true),
        'queue' => env('SORANE_EVENTS_QUEUE', true),
        'queue_name' => env('SORANE_EVENTS_QUEUE_NAME', 'default'),
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
