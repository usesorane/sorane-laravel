<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sorane\Laravel\Services\SoraneApiClient;

test('it sends error data to correct endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendError(['message' => 'Test error'], 'javascript');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/javascript-errors/store';
    });
});

test('it includes api key in authorization header', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key-123');

    $client->sendError(['message' => 'Test'], 'javascript');

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer test-key-123');
    });
});

test('it sends correct user agent header', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendError(['message' => 'Test'], 'log');

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Sorane-Laravel/log/1.0');
    });
});

test('it sends event data to events endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendEvent(['event_name' => 'test_event']);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/events/store';
    });
});

test('it sends page visit data to analytics endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendPageVisit(['url' => 'https://example.com']);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/page-visits/store';
    });
});

test('it returns true on successful response', function (): void {
    Http::fake([
        '*' => Http::response(['success' => true], 200),
    ]);

    $client = new SoraneApiClient('test-key');
    $result = $client->sendError(['message' => 'Test'], 'javascript');

    expect($result)->toBeTrue();
});

test('it returns false when api key is missing', function (): void {
    Http::fake();
    config(['sorane.key' => null]);
    $client = new SoraneApiClient(null);

    $result = $client->sendError(['message' => 'Test'], 'javascript');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});

test('it returns false on failed response', function (): void {
    Http::fake([
        '*' => Http::response(['error' => 'Failed'], 500),
    ]);

    $client = new SoraneApiClient('test-key');
    $result = $client->sendError(['message' => 'Test'], 'javascript');

    expect($result)->toBeFalse();
});

test('it handles network exceptions gracefully', function (): void {
    Http::fake(function (): void {
        throw new Exception('Network error');
    });

    $client = new SoraneApiClient('test-key');
    $result = $client->sendError(['message' => 'Test'], 'javascript');

    expect($result)->toBeFalse();
});

test('it uses correct endpoint for different error types', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->sendError(['message' => 'JS error'], 'javascript');
    Http::assertSent(fn ($req) => str_contains($req->url(), 'javascript-errors'));

    $client->sendError(['message' => 'Log error'], 'log');
    Http::assertSent(fn ($req) => str_contains($req->url(), 'logs'));

    $client->sendError(['message' => 'Other error'], 'other');
    Http::assertSent(fn ($req) => str_contains($req->url(), 'errors'));
});
