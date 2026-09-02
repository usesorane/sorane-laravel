---
name: ranetrace-worker
description: >-
  Set up, schedule and troubleshoot the Ranetrace worker (ranetrace:work) that
  flushes buffered errors, events, logs, analytics and JavaScript errors to the
  API. Use when buffers grow without draining, features show as paused, or
  nothing reaches Ranetrace.
---

# Ranetrace Worker

## When to use this skill

Use this skill when setting up, scheduling, or troubleshooting the Ranetrace worker (`ranetrace:work`). This is the command that flushes all buffered data (errors, events, logs, page visits, JavaScript errors) to the Ranetrace API. Without it running on a schedule, no data leaves the application.

## Running the Worker

```bash
# Process all feature types
php artisan ranetrace:work

# Process only a specific type
php artisan ranetrace:work --type=errors
php artisan ranetrace:work --type=events
php artisan ranetrace:work --type=logs
php artisan ranetrace:work --type=page_visits
php artisan ranetrace:work --type=javascript_errors
```

## Scheduling

The command must be scheduled in the application's `routes/console.php`. A one-minute interval is recommended:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ranetrace:work')->everyMinute()->withoutOverlapping()->runInBackground();
```

A queue worker must also be running to process the dispatched batch jobs. [Laravel Horizon](https://laravel.com/docs/horizon) is recommended for managing queue workers in production:

```bash
php artisan horizon
```

Alternatively, use the built-in queue worker:

```bash
php artisan queue:work --queue=default
```

If a custom queue name is configured via `RANETRACE_BATCH_QUEUE_NAME`, use that instead:

```bash
php artisan queue:work --queue=ranetrace
```

## Configuration

```php
// config/ranetrace.php
'batch' => [
    'queue_name' => env('RANETRACE_BATCH_QUEUE_NAME', 'default'),
    // Defaults to your app's cache store (CACHE_STORE / CACHE_DRIVER, → file).
    // For production / multi-worker setups point this at a shared, lock-capable
    // store (redis, memcached, or database) — never `array` (per-process).
    'cache_driver' => env('RANETRACE_BATCH_CACHE_DRIVER', env('CACHE_STORE', env('CACHE_DRIVER', 'file'))),
    'buffer_ttl' => env('RANETRACE_BATCH_BUFFER_TTL', 3600),         // 1 hour
    'max_buffer_size' => env('RANETRACE_BATCH_MAX_BUFFER_SIZE', 5000),
],
```

| Env Var | Description | Default |
|---|---|---|
| `RANETRACE_BATCH_QUEUE_NAME` | Queue name for batch jobs | `default` |
| `RANETRACE_BATCH_CACHE_DRIVER` | Cache store for the buffer (use a shared, lock-capable store in production) | app cache store (`CACHE_STORE` → `file`) |
| `RANETRACE_BATCH_BUFFER_TTL` | Buffer TTL in seconds before items expire | `3600` |
| `RANETRACE_BATCH_MAX_BUFFER_SIZE` | Max items per feature buffer before oldest are dropped | `5000` |

## Monitoring

```bash
# Check health status (buffer sizes, pauses, failed jobs)
php artisan ranetrace:status
php artisan ranetrace:status --json

# Clear pauses (set automatically on API errors)
php artisan ranetrace:pause-clear --global
php artisan ranetrace:pause-clear --feature=errors
php artisan ranetrace:pause-clear --all
```

## Troubleshooting

**Buffers growing but not draining:**
- Verify `ranetrace:work` is scheduled in `routes/console.php`
- Verify a queue worker is running: `php artisan queue:work`
- Check for pauses: `php artisan ranetrace:status`

**Features paused:**
- Check the reason in `ranetrace:status` output
- 401: verify `RANETRACE_KEY` in `.env` is valid
- 403: check subscription status and feature access
- 429: wait for auto-resume or reduce schedule frequency
- Clear manually: `php artisan ranetrace:pause-clear --feature=<type>`

**Cache driver not available:**
- The batch buffer uses the cache store set by `RANETRACE_BATCH_CACHE_DRIVER` (defaults to your app's cache store — `CACHE_STORE`/`CACHE_DRIVER`, i.e. `file` unless you've changed it)
- For production / multi-worker setups, point it at a shared, lock-capable store (`redis`, `memcached`, or `database`) and make sure that store is configured in `config/cache.php`
