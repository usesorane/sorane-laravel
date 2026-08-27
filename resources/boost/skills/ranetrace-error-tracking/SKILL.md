---
name: ranetrace-error-tracking
description: Track, investigate, and manage application errors with Ranetrace, including the MCP tools for AI-assisted debugging and for reading the monitored website's verdicts (uptime, performance, Lighthouse, certificate, domain, broken links).
---

# Ranetrace Error Tracking

## When to use this skill

Use this skill when working with error tracking, exception reporting, error investigation, or managing error states in a Ranetrace-monitored Laravel application.

## Reporting Errors

Capturing unhandled exceptions is **required wiring — it is NOT automatic.** Register Ranetrace on Laravel's exception handler in `bootstrap/app.php` with the package's one-liner:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Ranetrace\Laravel\Facades\Ranetrace;

->withExceptions(function (Exceptions $exceptions) {
    Ranetrace::handles($exceptions);
})
```

`Ranetrace::handles()` registers a `reportable` callback and preserves Laravel's own default error logging. Without this line, unhandled exceptions are **not** captured (manual `Ranetrace::report()` calls still work).

Manual reporting, for in-flow capture:

```php
try {
    // risky operation
} catch (Throwable $e) {
    Ranetrace::report($e);
}
```

## What Gets Captured

Each error report includes:
- Exception message (key=value secrets redacted), type, file, and line number
- Stack trace (truncated to 5000 chars; key=value secrets redacted)
- Code snippet (5 lines before and after the error line; each line length-capped)
- HTTP request data (URL with sensitive query params redacted, method, allowlisted headers only — the client IP / `x-forwarded-for` is masked, not captured)
- Authenticated user ID (email only when `ranetrace.errors.capture_user_email` is enabled; off by default)
- PHP and Laravel versions
- Environment name
- Console command details (for CLI errors)

## Configuration

```php
// config/ranetrace.php
'errors' => [
    'enabled' => env('RANETRACE_ERRORS_ENABLED', true),
    'queue' => env('RANETRACE_ERRORS_QUEUE', true),       // async via queue
    'queue_name' => env('RANETRACE_ERRORS_QUEUE_NAME', 'default'),
    'timeout' => env('RANETRACE_ERRORS_TIMEOUT', 10),
],
```

## MCP Tools for Error Investigation

Ranetrace hosts an MCP server with 31 tools: the 24 error and note tools below, plus 7 monitor tools (see *Monitor tools* at the end). It runs on Ranetrace, so there is nothing to install in the application and nothing to keep running. All it needs is an MCP token.

### The MCP token is not the ingest key

The tools authenticate with an MCP token, a separate credential from `RANETRACE_KEY`. The key writes captured telemetry in and lives on every server; the token reads data back out and belongs on the machine running the MCP client. Create it on the website's `/mcp` page in Ranetrace, where you can mint one per agent, each named for where it runs, so revoking one leaves the others working.

The token travels as a bearer header, so it stays with the client that uses it:

```bash
claude mcp add --transport http ranetrace https://api.ranetrace.com/mcp --header "Authorization: Bearer <token>"
```

Clients configured from a file, Cursor and VS Code among them, read the same server as an `mcpServers` entry:

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

An ingest key sent to an MCP endpoint returns a 403 with `error_code: MCP_TOKEN_REQUIRED`, and every tool surfaces that as instructions rather than a generic failure.

### The local MCP server has been removed

This package used to register a local MCP server when `laravel/mcp` was installed and `RANETRACE_MCP_TOKEN` was set (`php artisan mcp:start ranetrace`). That server, its tools and the `ranetrace.mcp` config block are gone; the hosted server above is the only one. To move an application that still has the old setup, point the client at the hosted URL with the same token, then drop `RANETRACE_MCP_TOKEN` from `.env`.

### Retrieving Errors

| Tool | Description |
|---|---|
| `LatestErrorsTool` | Fetch the most recent errors |
| `SearchErrorsTool` | Search errors with advanced filtering |
| `GetErrorTool` | Get full details of a specific error |
| `ErrorStatsTool` | Get error statistics and trends |
| `GetErrorActivityTool` | View the activity timeline for an error |

### Managing Error States

| Tool | Description |
|---|---|
| `ResolveErrorTool` | Mark an error as resolved |
| `ReopenErrorTool` | Reopen a previously resolved error |
| `IgnoreErrorTool` | Ignore an error (suppress future alerts) |
| `UnignoreErrorTool` | Stop ignoring an error |
| `SnoozeErrorTool` | Temporarily snooze an error |
| `UnsnoozeErrorTool` | Unsnooze a snoozed error |
| `DeleteErrorTool` | Soft-delete an error |
| `RestoreErrorTool` | Restore a deleted error |

### Bulk Operations

| Tool | Description |
|---|---|
| `BulkResolveErrorsTool` | Resolve multiple errors at once |
| `BulkReopenErrorsTool` | Reopen multiple errors |
| `BulkIgnoreErrorsTool` | Ignore multiple errors |
| `BulkDeleteErrorsTool` | Delete multiple errors |
| `BulkRestoreErrorsTool` | Restore multiple deleted errors |

### Investigation Notes

| Tool | Description |
|---|---|
| `CreateNoteTool` | Add a note to an error |
| `CreateNotesTool` | Add multiple notes at once |
| `ListNotesTool` | List all notes on an error |
| `GetNoteTool` | Get a specific note |
| `UpdateNoteTool` | Update a note |
| `DeleteNoteTool` | Delete a note |

## Monitor Tools

The same MCP server also answers for the website being monitored, not only the application's errors.

| Tool | Description |
|---|---|
| `GetMonitorStatusTool` | Which of my monitors needs a look: every enabled monitor with its verdict |
| `GetUptimeStatusTool` | Up or down, 24h uptime, and the recent outages |
| `GetPerformanceStatsTool` | 24h average response time and where that time goes |
| `GetLighthouseAuditTool` | Latest Lighthouse scores, metrics, trend, and ranked opportunities |
| `GetCertificateStatusTool` | HTTPS, issuer, validity window, days until expiry |
| `GetDomainStatusTool` | Registrar, expiry, DNSSEC, registrar locks |
| `GetBrokenLinksTool` | Broken links from the latest site audit, with the page each was found on |

None of them takes parameters: the MCP token already scopes every call to one website.

Each answers **verdict first** — what we found, why it matters, what to do — with the raw measurements following as evidence. Read the verdict and pass its wording on rather than re-deriving a conclusion from the numbers. A monitor that is switched off answers 409 `MONITOR_DISABLED` instead of returning stale figures, and the tool surfaces that message as-is.

## Testing

```bash
php artisan ranetrace:test-errors
```
