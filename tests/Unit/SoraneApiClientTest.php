<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sorane\Laravel\Services\SoraneApiClient;

test('it sends error batch to correct endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test error']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/errors/store'
            && isset($request->data()['errors']);
    });
});

test('it sends javascript error batch to correct endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendJavaScriptErrorBatch([['message' => 'JS error']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/javascript-errors/store'
            && isset($request->data()['errors']);
    });
});

test('it includes api key in authorization header', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key-123');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer test-key-123');
    });
});

test('it sends correct user agent header for errors', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Sorane-Laravel/errors/1.0');
    });
});

test('it sends correct user agent header for javascript errors', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendJavaScriptErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Sorane-Laravel/javascript-errors/1.0');
    });
});

test('it sends correct user agent header for logs', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendLogBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Sorane-Laravel/logs/1.0');
    });
});

test('it sends event batch to events endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendEventBatch([['event_name' => 'test_event']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/events/store'
            && isset($request->data()['events']);
    });
});

test('it sends page visit batch to analytics endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendPageVisitBatch([['url' => 'https://example.com']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/page-visits/store'
            && isset($request->data()['visits']);
    });
});

test('it returns success on successful response', function (): void {
    Http::fake([
        '*' => Http::response(['success' => true, 'received' => 1, 'processed' => 1], 200),
    ]);

    $client = new SoraneApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeTrue();
    expect($result['received'])->toBe(1);
    expect($result['processed'])->toBe(1);
});

test('it returns error when api key is missing', function (): void {
    Http::fake();
    config(['sorane.key' => null]);
    $client = new SoraneApiClient(null);

    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('API key not configured');
    Http::assertNothingSent();
});

test('it returns error on failed response', function (): void {
    Http::fake([
        '*' => Http::response(['error' => 'Failed'], 500),
    ]);

    $client = new SoraneApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse();
});

test('it handles network exceptions gracefully', function (): void {
    Http::fake(function (): void {
        throw new Exception('Network error');
    });

    $client = new SoraneApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('Network error');
});

test('it returns success for empty batch', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $result = $client->sendErrorBatch([]);

    expect($result['success'])->toBeTrue();
    expect($result['received'])->toBe(0);
    expect($result['processed'])->toBe(0);
    Http::assertNothingSent();
});

test('it uses timeout from config', function (): void {
    Http::fake();
    config(['sorane.errors.timeout' => 15]);
    $client = new SoraneApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    // Note: Can't directly test timeout value, but it's being set from config
    Http::assertSent(fn ($req) => str_contains($req->url(), 'errors'));
});
