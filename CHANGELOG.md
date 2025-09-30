# Changelog

All notable changes to `sorane-laravel` will be documented in this file.

## [Unreleased]

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
- Added test for closure serialization handling in `sorane:test-logging` command
- Added centralized `DataSanitizer` utility class for data serialization handling