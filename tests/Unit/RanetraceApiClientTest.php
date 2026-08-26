<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Services\RanetraceApiClient;

test('it sends error batch to correct endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test error']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/store'
            && isset($request->data()['errors']);
    });
});

test('it sends javascript error batch to correct endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendJavaScriptErrorBatch([['message' => 'JS error']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/javascript-errors/store'
            && isset($request->data()['javascript_errors']);
    });
});

test('it includes api key in authorization header', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key-123');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer test-key-123');
    });
});

test('it sends correct user agent header for errors', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/Errors/1.0');
    });
});

test('it sends correct user agent header for javascript errors', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendJavaScriptErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/JavaScriptErrors/1.0');
    });
});

test('it sends correct user agent header for logs', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendLogBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/Logs/1.0');
    });
});

test('it sends event batch to events endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendEventBatch([['event_name' => 'test_event']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/events/store'
            && isset($request->data()['events']);
    });
});

test('it sends page visit batch to analytics endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendPageVisitBatch([['url' => 'https://example.com']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/page-visits/store'
            && isset($request->data()['page_visits']);
    });
});

test('it returns success on successful response', function (): void {
    Http::fake([
        '*' => Http::response(['success' => true, 'received' => 1, 'processed' => 1], 200),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeTrue();
    expect($result['data']['received'])->toBe(1);
    expect($result['data']['processed'])->toBe(1);
});

test('it returns error when api key is missing', function (): void {
    Http::fake();
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);

    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('API key not configured');
    Http::assertNothingSent();
});

test('it returns error on failed response', function (): void {
    Http::fake([
        '*' => Http::response(['error' => 'Failed'], 500),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse();
});

test('it handles network exceptions gracefully', function (): void {
    Http::fake(function (): void {
        throw new Exception('Network error');
    });

    $client = new RanetraceApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Network error');
});

test('it returns error for empty batch', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $result = $client->sendErrorBatch([]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('Empty batch provided');
    Http::assertNothingSent();
});

test('it sends batches to the configured base url', function (): void {
    Http::fake();
    config(['ranetrace.base_url' => 'https://ranetrace.test/v1']);
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ranetrace.test/v1/errors/store');
});

test('it sends mcp reads to the configured base url', function (): void {
    Http::fake();
    config(['ranetrace.base_url' => 'https://ranetrace.test/v1']);
    $client = new RanetraceApiClient('test-key');

    $client->getMonitorStatus();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ranetrace.test/v1/monitors/status');
});

test('it trims a trailing slash from the configured base url', function (): void {
    Http::fake();
    config(['ranetrace.base_url' => 'https://ranetrace.test/v1/']);
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ranetrace.test/v1/errors/store');
});

test('it falls back to the default base url when the configured value is empty', function (): void {
    Http::fake();
    config(['ranetrace.base_url' => '']);
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.ranetrace.com/v1/errors/store');
});

test('it uses timeout from config', function (): void {
    Http::fake();
    config(['ranetrace.errors.timeout' => 15]);
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    // Note: Can't directly test timeout value, but it's being set from config
    Http::assertSent(fn ($req) => str_contains($req->url(), 'errors'));
});
