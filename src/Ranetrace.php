<?php

declare(strict_types=1);

namespace Ranetrace\Laravel;

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Ranetrace\Laravel\Events\EventTracker;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Support\Core;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\CoreDiagnostics;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\RouteSecretResolver;
use Ranetrace\Php\Errors\ErrorContext;
use Ranetrace\Php\Errors\PayloadBuilder;
use Ranetrace\Php\Events\EventItemBuilder;
use Throwable;

/**
 * The capture entry points: report a throwable, track an event.
 *
 * Neither payload is shaped here. Both wire shapes live in
 * `ranetrace/ranetrace-php` and are shared with that SDK, because the backend
 * does strict field-set matching and two descriptions of the same field set
 * eventually disagree. What this class owns is everything only Laravel can
 * answer: the request, the authenticated user, console detection, and which
 * path segments the ROUTER says are secret-bearing.
 */
class Ranetrace
{
    public function report(Throwable $exception): void
    {
        if (! $this->isCaptureEnabled('errors')) {
            return;
        }

        // Never capture an exception that Ranetrace itself threw. The host wires
        // report() into its exception handler, and Laravel's queue worker routes
        // EVERY job exception through that handler — so without this guard a
        // transport failure or internal bug in the package would be reported as
        // one of the customer's application errors and loop back into Ranetrace.
        if ($this->isInternalException($exception)) {
            return;
        }

        // Ranetrace must never throw from its capture path. Losing a single
        // error event is acceptable, breaking the host application is not.
        try {
            $data = $this->buildErrorPayload($exception);

            if (config('ranetrace.errors.queue', true)) {
                HandleErrorJob::dispatch($data);
            } else {
                HandleErrorJob::dispatchSync($data);
            }
        } catch (Throwable $e) {
            InternalLogger::error('Failed to capture exception', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function trackEvent(string $eventName, array $properties = [], int|string|null $userId = null, bool $validate = true): void
    {
        if (! $this->isCaptureEnabled('events')) {
            return;
        }

        // Validation stays OUTSIDE the try/catch: an invalid event name is a
        // developer mistake and should fail loudly during development.
        if ($validate) {
            EventTracker::ensureValidEventName($eventName);
        }

        // Everything past validation must never throw into the caller's
        // business logic — fail silently per the package's Core Rule.
        try {
            // getAuthIdentifier() is the safe contract method — works for any
            // Authenticatable, Eloquent or not. Skip the Auth lookup entirely
            // when the caller already provided a userId.
            if ($userId !== null) {
                $user = ['id' => $userId];
            } else {
                $authenticated = Auth::user();
                $user = $authenticated !== null
                    ? ['id' => $authenticated->getAuthIdentifier()]
                    : null;
            }

            $isConsole = app()->runningInConsole();
            $request = request();

            $fingerprints = Core::fingerprints();

            $eventData = (new EventItemBuilder(Core::scrubber()))->build(
                $eventName,
                $properties,
                $user,
                Carbon::now()->toIso8601String(),
                $isConsole ? null : $request->fullUrl(),
                $fingerprints->generateUserAgentHash($request->userAgent()),
                $fingerprints->generateSessionIdHash(
                    $request->ip(),
                    $request->userAgent(),
                    // Carbon rather than the generator's own clock, so a host
                    // (or a test) that freezes time sees the frozen day.
                    Carbon::now()->format('Y-m-d'),
                ),
                // The event's own URL is this request's, whose route the router
                // already bound; every OTHER url hiding in $properties describes
                // some other request and gets its own lookup.
                $isConsole ? null : RouteSecretResolver::resolver(RouteSecretResolver::forRequest($request)),
            );

            if (config('ranetrace.events.queue', true)) {
                HandleEventJob::dispatch($eventData);
            } else {
                HandleEventJob::dispatchSync($eventData);
            }
        } catch (Throwable $e) {
            InternalLogger::error('Failed to track event', [
                'event_name' => $eventName,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the exception was thrown from inside this package — i.e. it is one
     * of Ranetrace's own operational failures rather than a host application
     * error. Detection is by throw-site file only (getFile()), deliberately NOT
     * by walking the stack trace: the analytics middleware sits in every web
     * request's call stack, so a trace-based check would misclassify ordinary
     * host exceptions as internal and silently stop capturing them.
     */
    private function isInternalException(Throwable $exception): bool
    {
        return str_starts_with($exception->getFile(), __DIR__.DIRECTORY_SEPARATOR);
    }

    /**
     * Determine whether capture is enabled for the given feature: the package
     * must be enabled, the feature itself enabled, and an API key configured.
     */
    private function isCaptureEnabled(string $feature): bool
    {
        return config('ranetrace.enabled', true)
            && config("ranetrace.{$feature}.enabled", true)
            && ! empty(config('ranetrace.key'));
    }

    /**
     * Build the error payload sent to Ranetrace.
     *
     * Every cap, the header allowlist and the key order are the shared
     * builder's. This method's whole job is to say what Laravel observed: the
     * request, the console command line, the clock, and the route parameters
     * that hold a secret.
     *
     * @return array<string, mixed>
     */
    private function buildErrorPayload(Throwable $exception): array
    {
        return $this->payloadBuilder()->build(
            $exception,
            $this->errorContext(Request::instance(), app()->runningInConsole()),
        );
    }

    private function payloadBuilder(): PayloadBuilder
    {
        return new PayloadBuilder(CoreConfig::make(), Core::scrubber(), new CoreDiagnostics);
    }

    /**
     * What this request lets Laravel say about the error, before any shaping.
     */
    private function errorContext(HttpRequest $request, bool $isConsole): ErrorContext
    {
        return ErrorContext::provided(
            isConsole: $isConsole,
            headers: $isConsole ? null : $request->headers->all(),
            url: $isConsole ? null : $request->fullUrl(),
            method: $isConsole ? null : $request->method(),
            consoleCommand: $isConsole ? $this->consoleCommandLine() : null,
            consoleArguments: $isConsole ? $this->consoleArgv($request) : null,
            // Carbon rather than the builder's own clock, so a test (or a host)
            // that freezes time sees the frozen value in the payload.
            timestamp: Carbon::now()->toIso8601String(),
            sensitivePathValues: $isConsole ? null : RouteSecretResolver::forRequest($request),
            // The Referer describes a request that is NOT this one, so its route
            // has to be resolved separately, per URL.
            refererPathValues: RouteSecretResolver::forUrl(...),
        );
    }

    /**
     * The command line this process was started with, unscrubbed (the builder
     * redacts it). Null outside an Artisan invocation, where `$_SERVER['argv']`
     * belongs to whatever else launched the process.
     */
    private function consoleCommandLine(): ?string
    {
        return defined('ARTISAN_BINARY')
            ? implode(' ', $_SERVER['argv'] ?? [])
            : null;
    }

    /**
     * The same command line as an argv list, unbounded (the builder caps it).
     *
     * @return array<int, mixed>
     */
    private function consoleArgv(HttpRequest $request): array
    {
        $argv = $request->server('argv');

        return is_array($argv) ? $argv : [];
    }
}
