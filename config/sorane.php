<?php

return [
    'key' => env('SORANE_KEY'),
    'website_analytics' => [
        'enabled' => env('SORANE_WEBSITE_ANALYTICS_ENABLED', false),
        'queue' => env('SORANE_WEBSITE_ANALYTICS_QUEUE', 'default'),
    ],
];
