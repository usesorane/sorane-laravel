---
name: ranetrace-analytics
description: Set up and configure Ranetrace's privacy-first website analytics with bot detection, path filtering, and custom request filters.
---

# Ranetrace Website Analytics

## When to use this skill

Use this skill when setting up website analytics, configuring bot detection, excluding paths from tracking, or implementing custom request filters.

## How It Works

The `TrackPageVisit` middleware is auto-registered on the `web` middleware group when both `RANETRACE_ENABLED` and `RANETRACE_WEBSITE_ANALYTICS_ENABLED` are `true`. No manual middleware registration is needed.

Analytics is privacy-first: no cookies and no client-side scripts. Visitors are identified only by **salted, one-way HMAC hashes** — a user-agent hash and a daily-rotating session-id hash (IP + user agent + date, keyed by a per-install salt) — never raw identifiers, and never across sites. The raw IP and user agent are used transiently on the server and are not sent (unless `debug.preserve_user_agent` is enabled for local debugging).

## Configuration

```php
// config/ranetrace.php
'website_analytics' => [
    'enabled' => env('RANETRACE_WEBSITE_ANALYTICS_ENABLED', false),
    'queue' => env('RANETRACE_WEBSITE_ANALYTICS_QUEUE', true),
    'queue_name' => env('RANETRACE_WEBSITE_ANALYTICS_QUEUE_NAME', 'default'),
    'timeout' => env('RANETRACE_WEBSITE_ANALYTICS_TIMEOUT', 10),
    'excluded_paths' => [
        'horizon', 'nova', 'telescope', 'admin', 'filament', 'api', 'debugbar',
        'storage', 'livewire', '_debugbar', 'up', 'sanctum', '_ignition',
    ],
    'request_filter' => null,  // Custom filter class (FQCN)
    'user_agent' => [
        'min_length' => env('RANETRACE_WEBSITE_ANALYTICS_UA_MIN_LENGTH', 10),
        'max_length' => env('RANETRACE_WEBSITE_ANALYTICS_UA_MAX_LENGTH', 1000),
    ],
    'throttle_seconds' => env('RANETRACE_WEBSITE_ANALYTICS_THROTTLE_SECONDS', 30),
],
```

## Excluded Paths

The `excluded_paths` config array matches the **first URL segment**. To exclude `/admin/users`, add `'admin'` (this excludes all `/admin/*` routes).

The array replaces the default, it does not merge with it: copy the default list from the published config and append your own first segments, for example `'webhooks'` and `'health'`.

## Custom Request Filters

For advanced filtering logic, implement the `RequestFilter` contract:

```php
use Illuminate\Http\Request;
use Ranetrace\Laravel\Analytics\Contracts\RequestFilter;

class MyRequestFilter implements RequestFilter
{
    public function shouldSkip(Request $request): bool
    {
        // Skip tracking for internal API consumers
        if ($request->header('X-Internal-Client')) {
            return true;
        }

        // Skip tracking for specific IP ranges
        if (str_starts_with($request->ip(), '10.0.')) {
            return true;
        }

        return false;
    }
}
```

Register in config:

```php
'request_filter' => \App\Analytics\MyRequestFilter::class,
```

## Bot Detection

The middleware uses a multi-layer bot detection system:
1. **CrawlerDetect library** — comprehensive crawler detection
2. **Extra bot patterns** — additional bots not caught by CrawlerDetect (ChatGPT, Claude, social media crawlers, SEO bots, headless browsers)
3. **Suspicious user agent patterns** — filters curl, wget, python-requests, etc.
4. **Human probability scoring** — analyzes HTTP headers and patterns to score request likelihood of being human
5. **Header validation** — requires `Accept-Language` and meaningful `Accept` headers

## Throttling

Requests from the same IP to the same path are throttled to prevent duplicate tracking. Default: 30 seconds between tracked visits per IP/path combination. Configure with `RANETRACE_WEBSITE_ANALYTICS_THROTTLE_SECONDS`.

## What Gets Captured

Each page visit includes:
- Path, referrer, and UTM parameters (source, medium, campaign, term, content)
- Device type (mobile, tablet, desktop, console)
- Browser detection (Chrome, Firefox, Safari, Edge, Opera, etc.)
- Privacy-safe user agent hash and daily-rotating session ID hash
- Human probability score
- Country code (when available)

## Testing

```bash
php artisan ranetrace:test-analytics
```
