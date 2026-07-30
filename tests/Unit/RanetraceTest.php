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

test('maskAndBoundHeaders masks every header not on the allowlist and preserves nested array shape', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $masked = $method->invoke($ranetrace, [
        'user-agent' => ['Mozilla/5.0'],
        'accept' => ['text/html'],
        'authorization' => ['Bearer secret-token'],
        'cookie' => ['session=abc123'],
        'x-api-key' => ['super-secret-key'],
        'proxy-authorization' => ['Basic xyz'],
        'php-auth-pw' => ['hunter2'],
    ]);

    // Allowlisted headers are preserved as nested arrays
    expect($masked['user-agent'])->toBe(['Mozilla/5.0']);
    expect($masked['accept'])->toBe(['text/html']);

    // Non-allowlisted header values are masked; structure stays nested arrays
    expect($masked['authorization'])->toBe(['***']);
    expect($masked['cookie'])->toBe(['***']);
    expect($masked['x-api-key'])->toBe(['***']);
    expect($masked['proxy-authorization'])->toBe(['***']);
    expect($masked['php-auth-pw'])->toBe(['***']);
});

test('maskAndBoundHeaders truncates header values that exceed MAX_HEADER_VALUE_LENGTH', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $huge = str_repeat('a', 1000);
    $masked = $method->invoke($ranetrace, ['user-agent' => [$huge]]);

    // The value is truncated to <= 500 chars (MAX_HEADER_VALUE_LENGTH)
    expect(mb_strlen($masked['user-agent'][0]))->toBeLessThanOrEqual(500);
    expect($masked['user-agent'][0])->toEndWith('... (truncated)');
});

test('maskAndBoundHeaders caps header count at MAX_HEADER_COUNT', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $headers = [];
    for ($i = 0; $i < 80; $i++) {
        $headers["x-custom-{$i}"] = ['value'];
    }

    $masked = $method->invoke($ranetrace, $headers);

    expect(count($masked))->toBe(50);
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

test('error payload has exactly 18 fields', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect(count($payload))->toBe(18);
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

test('relativizePath strips the application base path', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'relativizePath');

    expect($method->invoke($ranetrace, base_path('app/Http/Controllers/UserController.php')))
        ->toBe('app/Http/Controllers/UserController.php');
});

test('relativizePath leaves paths outside base_path unchanged', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'relativizePath');

    expect($method->invoke($ranetrace, '/usr/local/share/elsewhere/File.php'))
        ->toBe('/usr/local/share/elsewhere/File.php');
});

// --- per-line context cap (R6-2) ---

test('capContextLine truncates an over-long source line and preserves the newline', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'capContextLine');

    $capped = $method->invoke($ranetrace, str_repeat('x', 5000)."\n");

    expect(mb_strlen($capped))->toBeLessThan(5000)
        ->and($capped)->toEndWith("... (truncated)\n");

    // A short line passes through unchanged.
    expect($method->invoke($ranetrace, "short line\n"))->toBe("short line\n");
});

// --- referer scrubbing in headers (R4-4) ---

test('maskAndBoundHeaders scrubs secrets from the referer query string', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $masked = $method->invoke($ranetrace, [
        'referer' => ['https://example.com/reset?token=abc123&page=2'],
    ]);

    expect($masked['referer'][0])->toBe('https://example.com/reset?token=[REDACTED]&page=2');
});

test('maskAndBoundHeaders scrubs a sensitive segment from the referer PATH', function (): void {
    Illuminate\Support\Facades\Route::get('/reset-password/{token}', fn (): string => 'reset');

    // The Referer describes a page the visitor was on before the error, so it
    // is matched against the application's routes rather than the current one.

    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $masked = $method->invoke($ranetrace, [
        'referer' => ['http://localhost/reset-password/live-reset-token-xyz789'],
    ]);

    expect($masked['referer'][0])->toBe('http://localhost/reset-password/[REDACTED]');
});

test('the error payload url has its sensitive path segment redacted', function (): void {
    $request = Request::create('http://localhost/reset-password/live-reset-token-xyz789?page=2', 'GET');
    $route = (new Route(['GET'], 'reset-password/{token}', fn (): string => 'reset'))->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'scrubRequestUrl');

    expect($method->invoke($ranetrace, $request))
        ->toBe('http://localhost/reset-password/[REDACTED]?page=2');
});

// --- client IP is not captured (R2-2) ---

test('maskAndBoundHeaders masks the client IP x-forwarded-for header', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $masked = $method->invoke($ranetrace, [
        'x-forwarded-for' => ['203.0.113.7, 198.51.100.2'],
        'x-forwarded-proto' => ['https'],
    ]);

    // The client IP chain is masked; the (non-PII) proto header stays plaintext.
    expect($masked['x-forwarded-for'])->toBe(['***'])
        ->and($masked['x-forwarded-proto'])->toBe(['https']);
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
