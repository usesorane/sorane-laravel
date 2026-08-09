<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;

beforeEach(function (): void {
    Bus::fake();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    config([
        'ranetrace.javascript_errors.enabled' => true,
        'ranetrace.javascript_errors.queue' => true,
        'ranetrace.javascript_errors.sample_rate' => 1.0,
    ]);
});

test('javascript error endpoint is registered', function (): void {
    $response = $this->post(route('ranetrace.javascript-errors.store'));

    // Should not be 404
    expect($response->status())->not->toBe(404);
});

test('it rejects requests when feature is disabled', function (): void {
    config(['ranetrace.javascript_errors.enabled' => false]);

    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
    ]);

    $response->assertStatus(403);
    $response->assertJson([
        'success' => false,
        'message' => 'JavaScript error tracking is not enabled',
    ]);
});

test('it validates required fields', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['message']);
});

test('it accepts valid error data', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error message',
        'stack' => 'Error: Test\n  at test.js:10',
        'type' => 'Error',
        'filename' => 'test.js',
        'line' => 10,
        'column' => 5,
        'url' => 'https://example.com/test',
        'timestamp' => now()->toISOString(),
    ]);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
});

test('it ignores errors matching ignored patterns', function (): void {
    config(['ranetrace.javascript_errors.ignored_errors' => ['ResizeObserver']]);

    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'ResizeObserver loop limit exceeded',
        'url' => 'https://example.com/',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'message' => 'Error ignored based on pattern',
    ]);
});

test('it sanitizes breadcrumbs', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'breadcrumbs' => [
            [
                'timestamp' => now()->toISOString(),
                'category' => 'user',
                'message' => 'Button clicked',
                'data' => ['button_id' => 'test'],
            ],
        ],
    ]);

    $response->assertStatus(200);
});

test('it limits breadcrumb count', function (): void {
    config(['ranetrace.javascript_errors.max_breadcrumbs' => 5]);

    $breadcrumbs = array_map(
        fn ($i) => [
            'timestamp' => now()->toISOString(),
            'category' => 'test',
            'message' => "Breadcrumb {$i}",
            'data' => [],
        ],
        range(1, 20)
    );

    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'breadcrumbs' => $breadcrumbs,
    ]);

    $response->assertStatus(200);
});

test('it includes browser info', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'browser_info' => [
            'screen_width' => 1920,
            'screen_height' => 1080,
            'viewport_width' => 1200,
            'viewport_height' => 800,
        ],
    ]);

    $response->assertStatus(200);
});

test('url is required when Referer header is absent', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['url']);
});

test('Referer header is used as url fallback before validation', function (): void {
    $response = $this->postJson(
        route('ranetrace.javascript-errors.store'),
        ['message' => 'Test error'],
        ['Referer' => 'https://example.com/from-referer']
    );

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
});

test('endpoint lives at /ranetrace/javascript-errors/store', function (): void {
    expect(route('ranetrace.javascript-errors.store', [], false))
        ->toBe('/ranetrace/javascript-errors/store');
});

test('it validates breadcrumb structure', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'breadcrumbs' => [
            [
                // Missing required fields
                'data' => [],
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['breadcrumbs.0.timestamp', 'breadcrumbs.0.category', 'breadcrumbs.0.message']);
});

test('it limits message length', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => str_repeat('a', 3000), // Exceeds max
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['message']);
});

test('it limits stack trace length', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test',
        'stack' => str_repeat('a', 15000), // Exceeds max
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['stack']);
});

test('it hashes the session id instead of sending it raw', function (): void {
    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        $sessionId = $job->getErrorData()['session_id'];

        return is_string($sessionId)
            && mb_strlen($sessionId) === 64           // HMAC-SHA256 hex
            && $sessionId !== session()->getId();  // not the raw id
    });
});

test('it scrubs secrets from the stack', function (): void {
    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'stack' => 'Error at https://api.test/x?token=abc123 in handler',
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        $stack = $job->getErrorData()['stack'];

        return str_contains($stack, 'token=[REDACTED]') && ! str_contains($stack, 'token=abc123');
    });
});

test('it redacts secrets from context and scrubs the url query', function (): void {
    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/reset?token=abc&page=2',
        'context' => ['api_key' => 'sk_live_xxx', 'page' => 'home'],
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        $data = $job->getErrorData();

        return $data['context']['api_key'] === '[REDACTED]'
            && $data['context']['page'] === 'home'
            && $data['url'] === 'https://example.com/reset?token=[REDACTED]&page=2';
    });
});

test('it scrubs a sensitive segment from the reported url PATH', function (): void {
    // The reported url is the page the error happened on, not this POST
    // endpoint, so it gets its own route lookup.
    Route::get('/reset-password/{token}', fn (): string => 'reset');

    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'http://localhost/reset-password/live-reset-token-xyz789',
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        $url = $job->getErrorData()['url'];

        return $url === 'http://localhost/reset-password/[REDACTED]'
            && ! str_contains($url, 'live-reset-token-xyz789');
    });
});

test('it scrubs a sensitive segment from a url that came in via the Referer fallback', function (): void {
    Route::get('/reset-password/{token}', fn (): string => 'reset');

    $this->withHeaders(['Referer' => 'http://localhost/reset-password/live-reset-token-xyz789'])
        ->postJson(route('ranetrace.javascript-errors.store'), [
            'message' => 'Test error',
        ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        return $job->getErrorData()['url'] === 'http://localhost/reset-password/[REDACTED]';
    });
});

test('it falls back to default ignored errors when the config key is absent', function (): void {
    // Simulate a published config that removed the ignored_errors key entirely.
    $jsConfig = config('ranetrace.javascript_errors');
    unset($jsConfig['ignored_errors']);
    config(['ranetrace.javascript_errors' => $jsConfig]);

    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'ResizeObserver loop limit exceeded',
        'url' => 'https://example.com/',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Error ignored based on pattern']);
    Bus::assertNotDispatched(HandleJavaScriptErrorJob::class);
});

test('it rejects an oversized browser_info connection_type', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'browser_info' => ['connection_type' => str_repeat('a', 100)], // exceeds max:50
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['browser_info.connection_type']);
});

test('it rejects a non-numeric browser_info dimension', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'browser_info' => ['screen_width' => 'not-a-number'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['browser_info.screen_width']);
});

test('it scrubs a sensitive path segment inside breadcrumb and context URLs', function (): void {
    // The top-level `url` was already path-redacted; the same reset link copied
    // into a navigation breadcrumb or into context used to ship the live token.
    Route::get('/reset-password/{token}', fn (): string => 'reset');

    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'http://localhost/',
        'context' => ['page' => 'http://localhost/reset-password/live-reset-token-xyz789'],
        'breadcrumbs' => [[
            'timestamp' => now()->toISOString(),
            'category' => 'navigation',
            'message' => 'Page loaded',
            'data' => ['url' => 'http://localhost/reset-password/live-reset-token-xyz789'],
        ]],
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        $data = $job->getErrorData();

        return $data['breadcrumbs'][0]['data']['url'] === 'http://localhost/reset-password/[REDACTED]'
            && $data['context']['page'] === 'http://localhost/reset-password/[REDACTED]';
    });
});

test('it scrubs a sensitive path segment from a RELATIVE breadcrumb URL', function (): void {
    // The fetch/XHR breadcrumb hooks record the argument the app passed, which
    // is usually relative rather than the absolute href.
    Route::get('/reset-password/{token}', fn (): string => 'reset');

    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'http://localhost/',
        'breadcrumbs' => [[
            'timestamp' => now()->toISOString(),
            'category' => 'http',
            'message' => 'fetch',
            'data' => ['url' => '/reset-password/live-reset-token-xyz789?next=/account'],
        ]],
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        return $job->getErrorData()['breadcrumbs'][0]['data']['url']
            === '/reset-password/[REDACTED]?next=/account';
    });
});

test('it rejects an oversized timestamp', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'timestamp' => str_repeat('a', 100), // exceeds max:64
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['timestamp']);
});

test('it rejects an oversized breadcrumb timestamp', function (): void {
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'breadcrumbs' => [[
            'timestamp' => str_repeat('a', 100), // exceeds max:64
            'category' => 'user',
            'message' => 'Button clicked',
        ]],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['breadcrumbs.0.timestamp']);
});

test('it scrubs secrets from the message as it does from the stack', function (): void {
    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Request to https://api.test/x?token=abc123 failed',
        'url' => 'https://example.com/',
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        $message = $job->getErrorData()['message'];

        return str_contains($message, 'token=[REDACTED]') && ! str_contains($message, 'abc123');
    });
});

test('it scrubs a stringified rejection object in the message', function (): void {
    // The bundled snippet's unhandledrejection handler JSON.stringifies the
    // rejection value into `message`, so a rejected API response lands here.
    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => '{"api_key":"sk_live_secret","status":"error"}',
        'url' => 'https://example.com/',
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        return ! str_contains($job->getErrorData()['message'], 'sk_live_secret');
    });
});

test('it accepts an explicit null breadcrumbs value', function (): void {
    // `nullable|array` permits null, and input()'s default does not replace a
    // stored null — passing it on used to raise a TypeError and a 500.
    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'breadcrumbs' => null,
    ]);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        return $job->getErrorData()['breadcrumbs'] === [];
    });
});

test('it scrubs sensitive query params inside URL-valued breadcrumb and context data (T10)', function (): void {
    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
        'context' => ['endpoint' => 'https://api.test/x?token=abc123&page=2'],
        'breadcrumbs' => [[
            'timestamp' => now()->toISOString(),
            'category' => 'http',
            'message' => 'fetch',
            'data' => ['request_url' => 'https://api.test/y?api_key=sk_live_zzz'],
        ]],
    ])->assertStatus(200);

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function ($job): bool {
        $data = $job->getErrorData();

        return $data['context']['endpoint'] === 'https://api.test/x?token=[REDACTED]&page=2'
            && $data['breadcrumbs'][0]['data']['request_url'] === 'https://api.test/y?api_key=[REDACTED]';
    });
});
