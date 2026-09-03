## Ranetrace

Ranetrace is an all-in-one monitoring package for Laravel providing error tracking, website analytics, event tracking, centralized logging, and JavaScript error tracking.

### Setup

- Config: `config/ranetrace.php` (publish with `php artisan vendor:publish --tag=ranetrace-laravel-config`)
- All env vars are prefixed with `RANETRACE_`
- Required: set `RANETRACE_KEY` and `RANETRACE_ENABLED=true` in `.env`
- Each feature has its own `enabled` toggle and can run via queue or synchronously
- **`RANETRACE_KEY` is the ingest key and nothing else.** It writes captured telemetry in and belongs on every server, in `.env`. Reading data back out is the MCP server's job, and it uses its own credential, held by the MCP client rather than by the application: an OAuth connection the user approves in the browser. Sending the ingest key to an MCP endpoint returns a 401 with `error_code: MCP_OAUTH_REQUIRED`. Never put an MCP credential in `.env`.

### Features & Env Vars

| Feature | Enable Env Var | Default |
|---|---|---|
| Error Tracking | `RANETRACE_ERRORS_ENABLED` | `true` |
| Event Tracking | `RANETRACE_EVENTS_ENABLED` | `true` |
| Centralized Logging | `RANETRACE_LOGGING_ENABLED` | `false` |
| Website Analytics | `RANETRACE_WEBSITE_ANALYTICS_ENABLED` | `false` |
| JavaScript Errors | `RANETRACE_JAVASCRIPT_ERRORS_ENABLED` | `false` |

### Error Tracking

Capturing unhandled exceptions is **required wiring — it is NOT automatic.** Register Ranetrace on Laravel's exception handler in `bootstrap/app.php`:

@verbatim
<code-snippet name="Wire Ranetrace into the exception handler" lang="php">
use Illuminate\Foundation\Configuration\Exceptions;
use Ranetrace\Laravel\Facades\Ranetrace;

->withExceptions(function (Exceptions $exceptions) {
    Ranetrace::handles($exceptions);
})
</code-snippet>
@endverbatim

Without this line, unhandled exceptions are NOT captured (though `Ranetrace::report($exception)` still works for in-flow calls). `Ranetrace::handles()` preserves Laravel's own default logging.

### Key Facades

- `Ranetrace` — error reporting (`Ranetrace::report($exception)`) and event tracking (`Ranetrace::trackEvent('event_name', $properties)`)
- `RanetraceEvents` — convenience methods for common events (sales, user registration, etc.)

### Middleware

The `TrackPageVisit` middleware is auto-registered on the `web` middleware group when analytics is enabled. Analytics is privacy-first: no cookies and no client-side scripts. Visitors are identified only by salted, one-way HMAC hashes (a user-agent hash and a daily-rotating session-id hash) — never raw identifiers, and never across sites.

### Blade Directive

Add `@ranetraceErrorTracking` before `</body>` to enable client-side JavaScript error tracking.

### Queue & Batch Processing

All features use queue-based processing by default. Captured items are buffered locally and sent to the API in batches by the batch worker:

@verbatim
<code-snippet name="Run the Ranetrace batch worker" lang="bash">
php artisan ranetrace:work
</code-snippet>
@endverbatim

### Logging Channel

The package **always registers** a `ranetrace` log channel, regardless of the feature flag, so no `config/logging.php` edit is required to define it (a user-defined `ranetrace` channel still wins). The channel is inert while logging is disabled: records short-circuit at the handler, so adding `'ranetrace'` to a committed log stack is safe in every environment. Route application logs to Ranetrace by adding `'ranetrace'` to your log stack, or use it directly via `Log::channel('ranetrace')`. Whether records are actually sent is what `RANETRACE_LOGGING_ENABLED` controls. The default minimum level is `notice` (tune via `RANETRACE_LOGGING_LEVEL`).

### MCP Server

Ranetrace hosts an MCP server with 33 tools: 24 for error investigation, note management and error state management, 7 monitor tools, and 2 for notification rules. It runs on Ranetrace, so there is nothing to install in the application and nothing to keep running.

**Connect over OAuth.** Any MCP client that supports OAuth connects with no pre-shared secret: add the server URL, and the client registers itself, sends the user to Ranetrace's approval screen, and gets its own credential back.

@verbatim
<code-snippet name="Add the hosted Ranetrace MCP server" lang="bash">
claude mcp add --transport http ranetrace https://api.ranetrace.com/mcp
</code-snippet>
@endverbatim

On claude.ai the same URL is added as a custom connector. Clients configured from a file (Cursor, VS Code) read the same server as an `mcpServers` entry with `"type": "http"` and the same URL, and need no `Authorization` header.

At the approval screen the user picks **exactly one website** the connection may reach, and whether the agent may write. Writes are off by default: a read-only connection reads errors, monitors, notes and notification rules, and does not see the write tools at all (they are absent from `tools/list`, not refused at call time). Turning on write actions adds error state changes, note create/update/delete, and notification-rule updates. Connections are listed and revoked on the account's agent connections page in Ranetrace, at `/user/profile/connections`, which also carries the connect instructions. A headless machine can use the device authorization grant instead, entering its code at `https://app.ranetrace.com/oauth/device`.

**MCP tokens are retired.** A static per-website token is no longer accepted: a client still sending one gets a 401 with `error_code: MCP_OAUTH_REQUIRED` and instructions to reconnect over OAuth.

An application with `RANETRACE_MCP_TOKEN` in `.env` is on a retired setup: there is no local MCP server to run. Delete the variable and connect the MCP client over OAuth as above.

The monitor tools are `get-monitor-status-tool` (which of my monitors needs a look), plus `get-uptime-status-tool`, `get-performance-stats-tool`, `get-lighthouse-audit-tool`, `get-certificate-status-tool`, `get-domain-status-tool` and `get-broken-links-tool`. None takes parameters: the connection scopes every call to one website. Each answers verdict first (what we found, why it matters, what to do) with the raw data following, so pass the verdict on rather than re-deriving a conclusion from the numbers. A monitor that is switched off answers 409 `MONITOR_DISABLED` rather than returning stale figures.

### Testing

@verbatim
<code-snippet name="Test all Ranetrace features" lang="bash">
php artisan ranetrace:test
php artisan ranetrace:status
</code-snippet>
@endverbatim

Individual test commands: `ranetrace:test-errors`, `ranetrace:test-events`, `ranetrace:test-logging`, `ranetrace:test-analytics`, `ranetrace:test-javascript-errors`.

### Common Pitfalls

- Both `RANETRACE_ENABLED=true` and the feature-specific env var must be set for any feature to work.
- Error tracking requires the `Ranetrace::handles($exceptions)` wiring in `bootstrap/app.php` (see *Error Tracking* above) — without it, unhandled exceptions are not captured.
- Batch buffering uses your app's cache store by default (`RANETRACE_BATCH_CACHE_DRIVER`, falling back to `CACHE_STORE`/`CACHE_DRIVER` → `file`). For production / multi-worker setups, point it at a shared, lock-capable store (`redis`, `memcached`, or `database`) — avoid `array` (per-process).
- The logging channel name `ranetrace_internal` is reserved for internal diagnostics — do not use it in your application. Self-logging is handled internally (the package writes its own diagnostics to that separate channel), so you do NOT need to add anything to `excluded_channels` to prevent loops.
