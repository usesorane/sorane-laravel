<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Events\EventTracker;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Ranetrace;

beforeEach(function (): void {
    Queue::fake();
});

// --- report(): capture gating ---

test('report does nothing when the package is disabled', function (): void {
    Config::set('ranetrace.enabled', false);

    (new Ranetrace)->report(new RuntimeException('boom'));

    Queue::assertNothingPushed();
});

test('report does nothing when error tracking is disabled', function (): void {
    Config::set('ranetrace.errors.enabled', false);

    (new Ranetrace)->report(new RuntimeException('boom'));

    Queue::assertNothingPushed();
});

test('report does nothing when no API key is configured', function (): void {
    Config::set('ranetrace.key', null);

    (new Ranetrace)->report(new RuntimeException('boom'));

    Queue::assertNothingPushed();
});

test('report dispatches HandleErrorJob when enabled and configured', function (): void {
    (new Ranetrace)->report(new RuntimeException('boom'));

    Queue::assertPushed(HandleErrorJob::class);
});

// --- report(): self-origin guard (never capture Ranetrace's own internal errors) ---

test('report ignores an exception thrown from inside the package', function (): void {
    // An exception whose throw-site is a package file represents one of
    // Ranetrace's own failures, not a host application error. Capturing it would
    // report the package's internals as the customer's bug and — via the
    // reportable() hook on a queued job exception — loop back into Ranetrace.
    try {
        EventTracker::ensureValidEventName('Invalid Name With Spaces');
        $internal = null;
    } catch (Throwable $e) {
        // $e->getFile() is .../src/Events/EventTracker.php
        $internal = $e;
    }

    expect($internal)->toBeInstanceOf(Throwable::class);

    (new Ranetrace)->report($internal);

    Queue::assertNothingPushed();
});

test('report still captures an exception thrown from host application code', function (): void {
    // Instantiated here (a stand-in for host code) — its throw-site is outside
    // the package, so it is captured as a normal application error. Guards the
    // self-origin check against ever becoming too broad.
    (new Ranetrace)->report(new RuntimeException('host app boom'));

    Queue::assertPushed(HandleErrorJob::class);
});

// --- report(): failure isolation (Core Rule — never throw from the capture path) ---

test('report never throws', function (): void {
    expect(fn () => (new Ranetrace)->report(new RuntimeException('boom')))
        ->not->toThrow(Throwable::class);
});

// --- handles(): reporting is additive, never stops the host's own logging (R2-5) ---

test('handles() reporting is additive and does not stop the host default logging', function (): void {
    $handler = new Illuminate\Foundation\Exceptions\Handler(app());
    $exceptions = new Illuminate\Foundation\Configuration\Exceptions($handler);

    // Wire Ranetrace exactly as bootstrap/app.php does. (Leading backslash: the
    // file aliases the concrete Ranetrace class via `use`, so the facade must be
    // fully qualified.)
    \Ranetrace\Laravel\Facades\Ranetrace::handles($exceptions);

    // A sentinel reportable registered AFTER Ranetrace's. Laravel stops the
    // report loop only when a callback returns false, so this runs ONLY if
    // Ranetrace's callback did not stop propagation — i.e. host logging survives.
    $sentinelRan = false;
    $handler->reportable(function (Throwable $e) use (&$sentinelRan): void {
        $sentinelRan = true;
    });

    $handler->report(new RuntimeException('boom'));

    expect($sentinelRan)->toBeTrue();
});

// --- trackEvent(): capture gating ---

test('trackEvent does nothing when the package is disabled', function (): void {
    Config::set('ranetrace.enabled', false);

    (new Ranetrace)->trackEvent('button_clicked');

    Queue::assertNothingPushed();
});

test('trackEvent does nothing when no API key is configured', function (): void {
    Config::set('ranetrace.key', null);

    (new Ranetrace)->trackEvent('button_clicked');

    Queue::assertNothingPushed();
});

test('trackEvent dispatches HandleEventJob for a valid event', function (): void {
    (new Ranetrace)->trackEvent('button_clicked', ['page' => 'home']);

    Queue::assertPushed(HandleEventJob::class);
});

test('trackEvent redacts secrets in event properties', function (): void {
    (new Ranetrace)->trackEvent('checkout_completed', [
        'api_key' => 'sk_live_x',
        'order_id' => 'ORD-1',
    ]);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $properties = $job->getEventData()['properties'];

        return $properties['api_key'] === '[REDACTED]'
            && $properties['order_id'] === 'ORD-1';
    });
});

test('trackEvent scrubs secrets inside URL values in event properties', function (): void {
    // `return_url` is not a sensitive key name, so only scrubDeep's URL-value
    // pass catches the token carried inside the URL's query string.
    (new Ranetrace)->trackEvent('checkout_completed', [
        'return_url' => 'https://shop.test/back?token=sk_live_x&ref=cart',
    ]);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $properties = $job->getEventData()['properties'];

        return $properties['return_url'] === 'https://shop.test/back?token=[REDACTED]&ref=cart';
    });
});

// --- trackEvent(): validation stays loud, the rest is isolated ---

test('trackEvent throws on an invalid event name when validation is enabled', function (): void {
    expect(fn () => (new Ranetrace)->trackEvent('Invalid Name!!'))
        ->toThrow(InvalidArgumentException::class);
});

test('trackEvent does not throw on an invalid event name when validation is disabled', function (): void {
    expect(fn () => (new Ranetrace)->trackEvent('Invalid Name!!', validate: false))
        ->not->toThrow(Throwable::class);
});

// --- header allowlist + bounded shape ---
//
// The allowlist itself, the per-value truncation and the fifty-header cap are
// the shared builder's, and ranetrace/ranetrace-php asserts them against the
// contract. What is Laravel's own, and so tested here, is that the request's
// HEADER BAG is the thing handed to that rule.

test('the request header bag is what reaches the masking rule', function (): void {
    $request = Request::create('http://localhost/orders', 'GET', server: [
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
        'HTTP_AUTHORIZATION' => 'Bearer secret-token',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 198.51.100.2',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    $headers = laravelErrorPayload(new RuntimeException('boom'), $request)['headers'];

    expect($headers['user-agent'])->toBe(['Mozilla/5.0'])
        ->and($headers['authorization'])->toBe(['***'])
        // The client IP chain is PII and is masked; the (non-PII) proto header
        // beside it stays plaintext.
        ->and($headers['x-forwarded-for'])->toBe(['***'])
        ->and($headers['x-forwarded-proto'])->toBe(['https']);
});

// --- error payload shape ---

test('error payload no longer contains the legacy `for` field', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload)->not->toHaveKey('for');
});

test('error payload no longer contains the always-null `console_options` field', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload)->not->toHaveKey('console_options');
});

test('error payload has exactly 19 fields', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect(count($payload))->toBe(19);
});

test('error payload sends the generic framework pair instead of laravel_version', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload['framework'])->toBe('laravel')
        ->and($payload['framework_version'])->toBe(app()->version())
        ->and($payload)->not->toHaveKey('laravel_version');
});

test('every built error payload key survives the job allow-list', function (): void {
    // The builder and HandleErrorJob::getAllowedKeys() are two spellings of one
    // wire contract. If they drift, filterPayload() silently strips the new key,
    // the item misses the strict field count, and the backend 422s the whole
    // batch (plus a 15-minute errors pause) while the suite stays green.
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    $allowedKeys = new ReflectionMethod(HandleErrorJob::class, 'getAllowedKeys')
        ->invoke(new HandleErrorJob([]));

    expect(collect($allowedKeys)->sort()->values()->all())
        ->toBe(collect($payload)->keys()->sort()->values()->all());
});

test('error payload uses an ISO 8601 timestamp, not the legacy time field', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload)->toHaveKey('timestamp')
        ->and($payload)->not->toHaveKey('time')
        ->and($payload['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

test('console_arguments is sent as an array, not a JSON-encoded string', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    // In the test environment runningInConsole() is true, so console_arguments is populated.
    expect($payload['console_arguments'])->toBeArray();
});

test('error payload redacts key=value secrets in the exception message', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('DB auth failed password=hunter2 for the worker'));

    expect($payload['message'])->toContain('[REDACTED]')
        ->and($payload['message'])->not->toContain('hunter2');
});

test('user payload uses getAuthIdentifier() and is null-safe for missing email', function (): void {
    $ranetrace = new Ranetrace;

    // A custom Authenticatable that has no `email` attribute at all.
    $user = new class implements Illuminate\Contracts\Auth\Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 42;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };

    Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($user);

    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload['user'])->toBe(['id' => 42, 'email' => null]);
});

// --- file-path relativization (R4-2) ---
//
// Relativizing (and left-truncating) a path is the shared builder's rule. What
// Laravel supplies is the root to relativize AGAINST, which is base_path().

test('the reported file path is relative to base_path, not the server layout', function (): void {
    $payload = invokeBuildErrorPayload(exceptionThrownAt(base_path('app/Http/Controllers/UserController.php')));

    expect($payload['file'])->toBe('app/Http/Controllers/UserController.php');
});

test('a file outside base_path keeps its absolute path', function (): void {
    $payload = invokeBuildErrorPayload(exceptionThrownAt('/usr/local/share/elsewhere/File.php'));

    expect($payload['file'])->toBe('/usr/local/share/elsewhere/File.php');
});

// --- path secrets resolved from the ROUTER (R4-4) ---
//
// Redacting a path segment is the shared builder's rule; knowing WHICH segment
// holds a secret is not something a path can be asked, so this is the half only
// Laravel can answer and the only half worth testing here.

test('the reported url redacts the segment the matched route names a token', function (): void {
    $request = Request::create('http://localhost/reset-password/live-reset-token-xyz789?page=2', 'GET');
    $route = (new Route(['GET'], 'reset-password/{token}', fn (): string => 'reset'))->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    expect(laravelErrorPayload(new RuntimeException('boom'), $request)['url'])
        ->toBe('http://localhost/reset-password/[REDACTED]?page=2');
});

test('a referer is matched against the route table on its own, not the current route', function (): void {
    Illuminate\Support\Facades\Route::get('/reset-password/{token}', fn (): string => 'reset');

    // The Referer describes a page the visitor was on BEFORE the error, so the
    // route bound to the current request says nothing about it.
    $request = Request::create('http://localhost/dashboard', 'GET', server: [
        'HTTP_REFERER' => 'http://localhost/reset-password/live-reset-token-xyz789?token=abc123&page=2',
    ]);

    expect(laravelErrorPayload(new RuntimeException('boom'), $request)['headers']['referer'])
        ->toBe(['http://localhost/reset-password/[REDACTED]?token=[REDACTED]&page=2']);
});

// --- user email is gated (R4-6) ---

test('user email is not captured by default', function (): void {
    Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn(makeAuthenticatableWithEmail(7, 'secret@example.com'));

    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload['user'])->toBe(['id' => 7, 'email' => null]);
});

test('user email is captured only when explicitly enabled', function (): void {
    Config::set('ranetrace.errors.capture_user_email', true);
    Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn(makeAuthenticatableWithEmail(7, 'secret@example.com'));

    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload['user'])->toBe(['id' => 7, 'email' => 'secret@example.com']);
});

/**
 * Helper: an Authenticatable whose `email` attribute is readable via getAttribute().
 */
function makeAuthenticatableWithEmail(int $id, ?string $email): Illuminate\Contracts\Auth\Authenticatable
{
    return new class($id, $email) implements Illuminate\Contracts\Auth\Authenticatable
    {
        public function __construct(private int $id, private ?string $email) {}

        public function getAttribute(string $key): mixed
        {
            return $key === 'email' ? $this->email : null;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

/**
 * Helper: invoke the private buildErrorPayload via reflection.
 *
 * @return array<string, mixed>
 */
function invokeBuildErrorPayload(Throwable $exception): array
{
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'buildErrorPayload');

    return $method->invoke($ranetrace, $exception);
}

/**
 * The payload a given HTTP request would produce.
 *
 * The suite runs under the CLI SAPI, so `runningInConsole()` is true and the
 * ordinary path nulls every request field. The console flag is therefore stated
 * rather than detected, which is the only way to reach the request branch at
 * all; everything else is the real capture path, context gathering included.
 *
 * @return array<string, mixed>
 */
function laravelErrorPayload(Throwable $exception, Request $request): array
{
    $ranetrace = new Ranetrace;

    $context = new ReflectionMethod($ranetrace, 'errorContext')->invoke($ranetrace, $request, false);
    $builder = new ReflectionMethod($ranetrace, 'payloadBuilder')->invoke($ranetrace);

    return $builder->build($exception, $context);
}

/**
 * An exception reporting a file of our choosing, so the path handling can be
 * exercised without arranging a throw from that exact location.
 */
function exceptionThrownAt(string $file): Exception
{
    $exception = new Exception('boom');

    new ReflectionProperty(Exception::class, 'file')->setValue($exception, $file);

    return $exception;
}
