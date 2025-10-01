# Sorane: Web Application Monitoring for Laravel.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/usesorane/sorane-laravel.svg?style=flat-square)](https://packagist.org/packages/usesorane/sorane-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/usesorane/sorane-laravel.svg?style=flat-square)](https://packagist.org/packages/usesorane/sorane-laravel)

[//]: # ([![GitHub Tests Action Status]&#40;https://img.shields.io/github/actions/workflow/status/usesorane/sorane-laravel/run-tests.yml?branch=main&label=tests&style=flat-square&#41;]&#40;https://github.com/usesorane/sorane-laravel/actions?query=workflow%3Arun-tests+branch%3Amain&#41;)
[//]: # ([![GitHub Code Style Action Status]&#40;https://img.shields.io/github/actions/workflow/status/usesorane/sorane-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square&#41;]&#40;https://github.com/usesorane/sorane-laravel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain&#41;)

Sorane is an all-in-one tool for Error Tracking, Website Analytics, and Website Monitoring for websites made with Laravel.

It alerts you about errors in your applications and provides the context you need to fix them.

Sorane’s Website Analytics is fully server-side, with a focus on privacy-first tracking.
It only collects essential visit data without cookies, invasive fingerprinting, or intrusive scripts.

It also keeps an eye on your website’s health. Sorane monitors uptime, performance, SSL certificates, domain and DNS status, Lighthouse scores, and broken links. So when something goes wrong, you’ll know.

Check out the [Sorane website](https://sorane.io) for more information.

## Installation

You can install the package via composer:

```bash
composer require usesorane/sorane-laravel
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="sorane-laravel-config"
```

This is the contents of the published config file:

```php
return [
    'key' => env('SORANE_KEY'),

    'error_reporting' => [
        'enabled' => env('SORANE_ERROR_REPORTING_ENABLED', true),
        'queue' => env('SORANE_ERROR_REPORTING_QUEUE', false),
        'queue_name' => env('SORANE_ERROR_REPORTING_QUEUE_NAME', 'default'),
        'timeout' => env('SORANE_ERROR_REPORTING_TIMEOUT', 5),
        'max_file_size' => env('SORANE_ERROR_REPORTING_MAX_FILE_SIZE', 1048576), // 1MB
        'max_trace_length' => env('SORANE_ERROR_REPORTING_MAX_TRACE_LENGTH', 5000),
    ],

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
        'queue' => env('SORANE_WEBSITE_ANALYTICS_QUEUE', true),
        'queue_name' => env('SORANE_WEBSITE_ANALYTICS_QUEUE_NAME', 'default'),
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
```

## Usage

### JavaScript Error Tracking

Sorane automatically captures JavaScript errors from your frontend application, providing full stack traces, browser context, and user interaction breadcrumbs to help you debug issues.

#### Quick Start

1. Enable JavaScript error tracking in your `.env`:

```env
SORANE_JAVASCRIPT_ERRORS_ENABLED=true
```

2. Add the tracking script to your layout file:

```blade
<!DOCTYPE html>
<html>
<head>
    <title>My App</title>
</head>
<body>
    @yield('content')
    
    @soraneErrorTracking
</body>
</html>
```

That's it! JavaScript errors are now automatically tracked.

#### Manual Error Capture

You can also manually capture errors with custom context:

```javascript
try {
  processPayment(amount);
} catch (error) {
  window.Sorane.captureError(error, {
    payment_amount: amount,
    user_type: 'premium'
  });
}
```

### Event Tracking

Sorane makes it easy to track custom events in your Laravel application. You can track anything from e-commerce events to user interactions.

**Privacy-First Approach**: Event tracking uses the same privacy-focused fingerprinting as website analytics:
- User agents are hashed with SHA256 (never stored in plain text)
- Session IDs are generated from hashed IP + user agent + date (daily rotation)
- IP addresses are never sent to Sorane
- Only essential data is collected for linking events and visits

#### Basic Event Tracking

```php
use Sorane\Laravel\Facades\Sorane;
use Sorane\Laravel\Events\EventTracker;

// Track a simple event (names must be snake_case)
Sorane::trackEvent('button_clicked', [
    'button_id' => 'header-cta',
    'page' => 'homepage'
]);

// Track an event with user ID
Sorane::trackEvent('feature_used', [
    'feature_name' => 'export_data',
    'export_type' => 'csv'
], $userId);

// Use predefined constants to prevent typos
Sorane::trackEvent(EventTracker::USER_REGISTERED, [
    'registration_source' => 'website'
], $userId);
```

#### Event Name Validation

Sorane enforces strict event naming conventions to ensure consistency and prevent categorization issues:

- **Format**: `snake_case` (lowercase with underscores)
- **Length**: 3-50 characters
- **Start**: Must begin with a letter
- **Characters**: Only letters, numbers, and underscores allowed

**Valid examples**: `user_registered`, `product_added_to_cart`, `newsletter_signup`  
**Invalid examples**: `User Registered`, `product-added`, `123_event`, `user@registered`

```php
// ✅ Valid - uses snake_case format
Sorane::trackEvent('newsletter_signup', ['source' => 'footer']);

// ❌ Invalid - will throw InvalidArgumentException
Sorane::trackEvent('Newsletter Signup', ['source' => 'footer']);

// ✅ Use constants to avoid validation issues
use Sorane\Laravel\Events\EventTracker;
Sorane::trackEvent(EventTracker::NEWSLETTER_SIGNUP, ['source' => 'footer']);

// ✅ Bypass validation if needed (advanced usage)
Sorane::trackEvent('Legacy Event Name', [], null, false);
```

#### E-commerce Event Tracking

Sorane provides convenient helper methods for common e-commerce events with predefined naming:

```php
use Sorane\Laravel\Facades\SoraneEvents;

// Track product added to cart
SoraneEvents::productAddedToCart(
    productId: 'PROD-123',
    productName: 'Awesome Widget',
    price: 29.99,
    quantity: 2,
    category: 'Widgets',
    additionalProperties: ['color' => 'blue', 'size' => 'large']
);

// Track a sale
SoraneEvents::sale(
    orderId: 'ORDER-456',
    totalAmount: 89.97,
    products: [
        [
            'id' => 'PROD-123',
            'name' => 'Awesome Widget',
            'price' => 29.99,
            'quantity' => 2,
        ]
    ],
    currency: 'USD',
    additionalProperties: ['payment_method' => 'credit_card']
);

// Track user registration
SoraneEvents::userRegistered(
    userId: 123,
    additionalProperties: ['registration_source' => 'website']
);

// Track user login
SoraneEvents::userLoggedIn(
    userId: 123,
    additionalProperties: ['login_method' => 'email']
);

// Track custom page views
SoraneEvents::pageView(
    pageName: 'Product Details',
    additionalProperties: ['product_id' => 'PROD-123']
);

// Track custom events with validation
SoraneEvents::custom(
    eventName: 'newsletter_signup',
    properties: ['source' => 'footer'],
    userId: 123
);

// Track custom events without validation (advanced usage)
SoraneEvents::customUnsafe(
    eventName: 'Legacy Event Name',
    properties: ['source' => 'migration']
);
```

#### Available Event Constants

Use predefined constants to ensure consistent naming and avoid typos:

```php
use Sorane\Laravel\Events\EventTracker;

EventTracker::PRODUCT_ADDED_TO_CART      // 'product_added_to_cart'
EventTracker::PRODUCT_REMOVED_FROM_CART  // 'product_removed_from_cart'
EventTracker::CART_VIEWED               // 'cart_viewed'
EventTracker::CHECKOUT_STARTED          // 'checkout_started'
EventTracker::CHECKOUT_COMPLETED        // 'checkout_completed'
EventTracker::SALE                      // 'sale'
EventTracker::USER_REGISTERED           // 'user_registered'
EventTracker::USER_LOGGED_IN           // 'user_logged_in'
EventTracker::USER_LOGGED_OUT          // 'user_logged_out'
EventTracker::PAGE_VIEW                // 'page_view'
EventTracker::SEARCH                   // 'search'
EventTracker::NEWSLETTER_SIGNUP        // 'newsletter_signup'
EventTracker::CONTACT_FORM_SUBMITTED   // 'contact_form_submitted'
```

#### Configuration

Event tracking can be configured in your `config/sorane.php` file:

```php
'events' => [
    'enabled' => env('SORANE_EVENTS_ENABLED', true),
    'queue' => env('SORANE_EVENTS_QUEUE', true),
    'queue_name' => env('SORANE_EVENTS_QUEUE_NAME', 'default'),
],
```

- `enabled`: Enable or disable event tracking
- `queue`: Whether to send events via Laravel queues (recommended for production)
- `queue_name`: Which queue to use for sending events

#### Testing Event Tracking

You can test your event tracking setup using the included command:

```bash
php artisan sorane:test-events
```

This will send various test events to your Sorane dashboard.

### Centralized Logging

Sorane provides centralized logging capabilities that capture and store all your application logs in one place. This makes it easy to monitor, search, and analyze log data across your entire application.

**Laravel Integration**: The recommended approach is to integrate Sorane with Laravel's built-in logging system using log stacks.

#### Laravel Logging Integration (Recommended)

Add the Sorane driver to your `config/logging.php`:

```php
'channels' => [
    'sorane' => [
        'driver' => 'sorane',
        'level' => 'error', // Control which levels are sent to Sorane
    ],
    
    // Recommended: Create a stack for production
    'production' => [
        'driver' => 'stack',
        'channels' => array_merge(explode(',', env('LOG_STACK', 'single')), ['sorane']),
        'ignore_exceptions' => false,
    ],
],
```

Set your log channel in `config/app.php` or `.env`:

```env
LOG_CHANNEL=production
```

Now all your application logs will automatically be sent to both files and Sorane:

```php
use Illuminate\Support\Facades\Log;

// These automatically go to both file and Sorane
Log::error('Database connection failed', [
    'database' => 'mysql',
    'error_code' => 1045,
]);

Log::critical('System overload detected', [
    'cpu_usage' => '98%',
    'memory_usage' => '95%',
]);

// Use specific channels when needed
Log::channel('sorane')->error('This goes only to Sorane');
```

#### Configuration

Logging can be configured in your `config/sorane.php` file:

```php
'logging' => [
    'enabled' => env('SORANE_LOGGING_ENABLED', false),
    'queue' => env('SORANE_LOGGING_QUEUE', true),
    'queue_name' => env('SORANE_LOGGING_QUEUE_NAME', 'default'),
    'excluded_channels' => ['sorane'],
],
```

Add to your `.env` file:
```env
SORANE_LOGGING_ENABLED=true
```

**Optional Configuration (with defaults):**
```env
# Optional: Use queues for logging (default: true - recommended for production)
SORANE_LOGGING_QUEUE=true

# Optional: Custom queue name for log jobs (default: default)
SORANE_LOGGING_QUEUE_NAME=default
```

All optional settings use sensible defaults, so you only need to set them if you want to customize the behavior.

#### Available Log Levels

Standard PSR-3 log levels are supported:

- `emergency` - System is unusable
- `alert` - Action must be taken immediately  
- `critical` - Critical conditions
- `error` - Error conditions
- `warning` - Warning conditions
- `notice` - Normal but significant condition
- `info` - Informational messages
- `debug` - Debug-level messages

#### Testing Logging

You can test your logging configuration using the included command:

```bash
php artisan sorane:test-logging
```

This will send various test logs to your Sorane dashboard and display your current configuration.

### Error Tracking & Website Analytics

Please refer to the [Sorane website](https://sorane.io) for more information on how to use Sorane.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sorane](https://github.com/usesorane)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
