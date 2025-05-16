# Error Tracking, Website Analytics and Website Monitoring for Laravel

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
        'ignore_if_user' => null,
    ],
];
```

## Usage

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
