# Ranetrace: Web Application Monitoring for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ranetrace/ranetrace-laravel.svg?style=flat-square)](https://packagist.org/packages/ranetrace/ranetrace-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/ranetrace/ranetrace-laravel.svg?style=flat-square)](https://packagist.org/packages/ranetrace/ranetrace-laravel)

Ranetrace is an all-in-one tool for **Error Tracking**, **Website Analytics**, and **Website Monitoring** for Laravel applications.

- Alerts you about errors and provides the context you need to fix them
- Privacy-first, fully server-side website analytics — no cookies and no client-side scripts; visitors are identified only by salted, one-way hashes (never raw identifiers, never across sites)
- Monitors uptime, performance, SSL certificates, domain and DNS status, Lighthouse scores, and broken links

Check out the [Ranetrace website](https://ranetrace.com) for more information.

## Installation

Install the package via Composer:

```bash
composer require ranetrace/ranetrace-laravel
```

Add your Ranetrace key to `.env`:

```env
RANETRACE_KEY=your-key-here
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="ranetrace-laravel-config"
```

### Schedule the work command

Captured items (errors, events, logs, page visits, JS errors) are buffered locally and sent to Ranetrace in batches by the `ranetrace:work` artisan command. Add it to your scheduler:

```php
// In your scheduler (routes/console.php)
Schedule::command('ranetrace:work')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
```

> **Don't skip this step.** Without it, buffered telemetry never reaches Ranetrace. Run `php artisan ranetrace:status` at any time to verify health and see whether buffers are draining.

## Usage

### Error Tracking

Wire Ranetrace into Laravel's exception handling in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Ranetrace\Laravel\Facades\Ranetrace;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withExceptions(function (Exceptions $exceptions) {
        Ranetrace::handles($exceptions);
    })
    ->create();
```

That's it — every unhandled exception is now reported to your Ranetrace dashboard (alongside Laravel's normal logging). You can also capture exceptions in-flow:

```php
use Ranetrace\Laravel\Facades\Ranetrace;

try {
    // ...
} catch (Throwable $e) {
    Ranetrace::report($e);
    throw $e;
}
```

Test your setup with:

```bash
php artisan ranetrace:test-errors
```

### JavaScript Error Tracking

1. Enable it in your `.env`:

```env
RANETRACE_JAVASCRIPT_ERRORS_ENABLED=true
```

2. Add the Blade directive to your layout:

```blade
<body>
    @yield('content')

    @ranetraceErrorTracking
</body>
```

The directive injects a small script that captures `window.onerror`, unhandled promise rejections, and (optionally) `console.error` calls. It also collects breadcrumbs for clicks, form submissions, and XHR/fetch activity to give you context around each error.

You can also capture errors manually:

```javascript
window.Ranetrace.captureError(error, { payment_amount: amount });
```

### Event Tracking

Track custom events with a privacy-first approach — no IP addresses are stored, user agents are hashed, and session IDs rotate daily.

```php
use Ranetrace\Laravel\Facades\Ranetrace;

Ranetrace::trackEvent('button_clicked', [
    'button_id' => 'header-cta',
    'page' => 'homepage'
]);
```

E-commerce helpers are available via the `RanetraceEvents` facade:

```php
use Ranetrace\Laravel\Facades\RanetraceEvents;

RanetraceEvents::sale(
    orderId: 'ORDER-456',
    totalAmount: 89.97,
    products: [['id' => 'PROD-123', 'name' => 'Widget', 'price' => 29.99, 'quantity' => 3]],
    currency: 'USD'
);
```

Test your setup with:

```bash
php artisan ranetrace:test-events
```

### Centralized Logging

Enable it in your `.env`:

```env
RANETRACE_LOGGING_ENABLED=true
```

The package always registers a `ranetrace` log channel, so no `config/logging.php` edit is required to define it. It stays inert until logging is enabled, so you can add it to your existing log stack right away to route application logs to both your normal destination and Ranetrace:

```php
// config/logging.php — example stacked channel
'channels' => [
    'production' => [
        'driver' => 'stack',
        'channels' => array_merge(explode(',', env('LOG_STACK', 'single')), ['ranetrace']),
        'ignore_exceptions' => false,
    ],
],
```

Then point Laravel at it:

```env
LOG_CHANNEL=production
```

By default the package captures `notice` and above. Tune via `RANETRACE_LOGGING_LEVEL`.

Test your setup with:

```bash
php artisan ranetrace:test-logging
```

### Website Analytics

Enable it in your `.env`:

```env
RANETRACE_WEBSITE_ANALYTICS_ENABLED=true
```

The `TrackPageVisit` middleware is automatically added to the `web` middleware group. It applies extensive bot and crawler filtering before sending visits to your Ranetrace dashboard. No code changes needed.

See the [Ranetrace website](https://ranetrace.com) for dashboard setup and configuration details.

## MCP server

Ranetrace hosts an MCP server so an agent can investigate errors and read your website's monitor verdicts without leaving the editor. It runs on Ranetrace, not in your application, so there is nothing to install and nothing to keep running. All you need is an MCP token, which you create on your website's `/mcp` page in Ranetrace.

The MCP token is a separate credential from `RANETRACE_KEY`. The key sends captured data in and lives on every production server; the token reads data back out and belongs on the machine running the MCP client. You can create as many tokens as you have agents, each named for where it runs, so revoking one leaves the others working.

### Configure it in your MCP client

The token travels as a bearer header, which keeps it with the client that uses it rather than in your application's environment:

```bash
claude mcp add --transport http ranetrace https://api.ranetrace.com/mcp --header "Authorization: Bearer <token>"
```

Clients that are configured from a file, Cursor and VS Code among them, read the same server as an `mcpServers` entry:

```json
{
  "mcpServers": {
    "ranetrace": {
      "type": "http",
      "url": "https://api.ranetrace.com/mcp",
      "headers": { "Authorization": "Bearer <token>" }
    }
  }
}
```

### The local MCP server has been removed

Before the hosted server existed, this package shipped its own MCP server, registered when `laravel/mcp` was installed and `RANETRACE_MCP_TOKEN` was set. That server, its tools and the `ranetrace.mcp` config block are gone.

Moving over is one line of client config: point the client at `https://api.ranetrace.com/mcp` with the command above, using the same token. You can then drop `RANETRACE_MCP_TOKEN` from your `.env` and `laravel/mcp` from your application, if nothing else needs them.

### What the tools answer

- Errors: search and investigate with filtering, full error details, statistics, activity, investigation notes, and error state (resolve, ignore, snooze, delete, restore).
- Monitors: which of your monitors needs a look, and the detail behind any one of them (uptime, performance, Lighthouse, certificate, domain, broken links).

Every monitor tool answers verdict first: what we found, why it matters, what to do, the same guidance you read on the dashboard, with the raw measurements following as its evidence.

## Health Check

The package gives you two ways to see what it's doing locally: a CLI command and an in-app dashboard. Both read the same underlying diagnostics, so they can never disagree.

### Status command

At any time, from the terminal:

```bash
php artisan ranetrace:status
```

Reports overall health, configured features, buffer sizes, pause states (if the API has rate-limited you), and recent failed jobs — both as formatted output and via `--json` for monitoring integrations. When the dashboard is enabled, it also prints a one-line link to it.

### Diagnostics dashboard

An in-app health page at `/ranetrace`, in the spirit of Laravel Horizon and Pulse. It shows whether *your* installation is correctly wired up and data is flowing: a configuration snapshot, misconfiguration checks (missing API key, volatile cache driver, stalled worker, near-capacity buffers, and more), pipeline buffers, pauses, failed jobs, a tail of the internal log, and the routes/middleware the package actually registered. It links out to the hosted Ranetrace dashboard for the captured data itself.

It is read-only, makes no outbound calls, and degrades gracefully — a failing cache or database renders a degraded panel rather than throwing into your app.

**Access is local-only by default.** Exactly like Horizon, Pulse, and Telescope, the dashboard is not reachable outside the `local` environment until you explicitly grant access. Define the `viewRanetrace` gate in `app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewRanetrace', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});
```

The dashboard is configurable in `config/ranetrace.php`, all overridable via `.env`:

```env
RANETRACE_DASHBOARD_ENABLED=true            # set false to remove the routes entirely
RANETRACE_DASHBOARD_PATH=ranetrace          # the URL path
RANETRACE_DASHBOARD_REFRESH=10              # auto-refresh interval in seconds (0 = off)
RANETRACE_DASHBOARD_DOMAIN=                 # optional: serve on a dedicated domain
RANETRACE_DASHBOARD_HOSTED_URL=https://ranetrace.com  # "View captured data" link target
```

The page auto-refreshes its panels every `RANETRACE_DASHBOARD_REFRESH` seconds without a full reload. It is registered independently of the master `RANETRACE_ENABLED` switch, so if capture is disabled or misconfigured you can still open the dashboard to see why.

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

- [Ranetrace](https://github.com/ranetrace)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
