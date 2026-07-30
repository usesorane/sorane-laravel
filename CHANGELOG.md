# Changelog

All notable changes to `ranetrace-laravel` will be documented in this file.

## [Unreleased]

### Security
- Telemetry now redacts sensitive route-parameter values from URL **path** segments, not just query strings. A segment is treated as secret when the route that owns it names it sensitively — e.g. the `{token}` of `reset-password/{token}`, the `{hash}` of `email/verify/{id}/{hash}`, or the binding field of a custom-key binding such as `{invitation:token}`. Those pages remain tracked (as e.g. `/reset-password/[REDACTED]`), so the page-view signal is preserved. This is best-effort by design: the route definition is the only available oracle for which segment holds a secret, so a parameter named `{code}` or `{t}` cannot be recognised — add such names to `ranetrace.scrubbing.extra_keys` to cover them
- Path redaction is applied everywhere a URL leaves the host, not only to the page-visit `url`/`path`: the visit `referrer`, the `Referer` header captured on error payloads, the error and event `url`, and the page URL reported by the JavaScript error snippet. The referrer cases matter on their own — a same-origin navigation sends the full previous URL by default, so a live reset token would otherwise arrive one page-view after `/reset-password/{token}` was itself redacted. URLs that did not originate from the current request are matched against the application's own routes to find their sensitive segments

### Fixed
- Website analytics no longer counts non-GET requests as page visits (form submissions, `broadcasting/auth`, Livewire/Inertia XHR)
- Requests carrying the `X-Livewire` header are always skipped — Livewire 4 serves its endpoint from `/livewire-{hash}/update` (hash derived from `APP_KEY`), which the static `livewire` excluded-path entry can no longer match
- The package no longer tracks visits to its own diagnostics dashboard; the skip follows the configured `dashboard.path`/`dashboard.domain` instead of a hardcoded prefix

### Added
- New default `excluded_paths` entries: `up`, `sanctum`, `_ignition` (note: apps that already published `config/ranetrace.php` keep their frozen list and must add these manually)

### Changed
- Refactored package to use native Laravel service provider instead of Spatie Laravel Package Tools
- Removed `spatie/laravel-package-tools` dependency
- Service provider now extends `Illuminate\Support\ServiceProvider` directly
- All functionality preserved (config publishing, commands registration, middleware, singleton bindings, log driver)

## [v1.0.22]

### Fixed
- Fixed "Serialization of 'Closure' is not allowed" error when queuing logs or events that contain closures or other non-serializable objects
- Added data sanitization for logging and event tracking jobs (page visit tracking unaffected as it only handles basic request data)
- Closures are now safely converted to `[Closure]` placeholders
- Complex objects are converted to string representations or class names when possible
- Resources and other non-serializable types are handled gracefully

### Added
- Added test for closure serialization handling in `ranetrace:test-logging` command
- Added centralized `DataSanitizer` utility class for data serialization handling