# Error Tracking for Laravel with Sorane

[![Latest Version on Packagist](https://img.shields.io/packagist/v/usesorane/sorane-laravel.svg?style=flat-square)](https://packagist.org/packages/usesorane/sorane-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/usesorane/sorane-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/usesorane/sorane-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/usesorane/sorane-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/usesorane/sorane-laravel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/usesorane/sorane-laravel.svg?style=flat-square)](https://packagist.org/packages/usesorane/sorane-laravel)

This is the Laravel package for Sorane. Sorane is a simple error tracking tool for developers. It helps you to track errors in your applications and provides you with the necessary information to fix them.

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
