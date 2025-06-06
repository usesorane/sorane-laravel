# Error Sorane is an all-in-one tool for Error Tracking, Website Analytics, Event Tracking, and Website Monitoring for websites made with Laravel.

It alerts you about errors in your applications and provides the context you need to fix them.

Sorane's Website Analytics is fully server-side, with a focus on privacy-first tracking.
It collects only essential visit data without cookies, invasive fingerprinting, or intrusive scripts.

Sorane's Event Tracking allows you to track custom events in your application, such as product purchases, user registrations, and other important business metrics.

It also keeps an eye on your website's health. Sorane monitors uptime, performance, SSL certificates, domain and DNS status, Lighthouse scores, and broken links. So when something goes wrong, you'll know., Website Analytics, Event Tracking and Website Monitoring for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/usesorane/sorane-laravel.svg?style=flat-square)](https://packagist.org/packages/usesorane/sorane-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/usesorane/sorane-laravel.svg?style=flat-square)](https://packagist.org/packages/usesorane/sorane-laravel)

[//]: # ([![GitHub Tests Action Status]&#40;https://img.shields.io/github/actions/workflow/status/usesorane/sorane-laravel/run-tests.yml?branch=main&label=tests&style=flat-square&#41;]&#40;https://github.com/usesorane/sorane-laravel/actions?query=workflow%3Arun-tests+branch%3Amain&#41;)
[//]: # ([![GitHub Code Style Action Status]&#40;https://img.shields.io/github/actions/workflow/status/usesorane/sorane-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square&#41;]&#40;https://github.com/usesorane/sorane-laravel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain&#41;)

Sorane is an all-in-one tool for Error Tracking, Website Analytics, and Website Monitoring for websites made with Laravel.

It alerts you about errors in your applications and provides the context you need to fix them.

Sorane’s Website Analytics is fully server-side, with a focus on privacy-first tracking.
It collects only essential visit data without cookies, invasive fingerprinting, or intrusive scripts.

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
```

## Usage

### Event Tracking

Sorane makes it easy to track custom events in your Laravel application. You can track anything from e-commerce events to user interactions.

**Privacy-First Approach**: Event tracking uses the same privacy-focused fingerprinting as website analytics:
- User agents are hashed with SHA256 (never stored in plain text)
- Session IDs are generated from hashed IP + user agent + date (daily rotation)
- IP addresses are never sent to Sorane
- Only essential data is collected for linking events and visits

#### Basic Event Tracking

```php
use Sorane\ErrorReporting\Facades\Sorane;
use Sorane\ErrorReporting\Events\EventTracker;

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
use Sorane\ErrorReporting\Events\EventTracker;
Sorane::trackEvent(EventTracker::NEWSLETTER_SIGNUP, ['source' => 'footer']);

// ✅ Bypass validation if needed (advanced usage)
Sorane::trackEvent('Legacy Event Name', [], null, false);
```

#### E-commerce Event Tracking

Sorane provides convenient helper methods for common e-commerce events with predefined naming:

```php
use Sorane\ErrorReporting\Facades\SoraneEvents;

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
use Sorane\ErrorReporting\Events\EventTracker;

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
