<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Services\RanetraceApiClient;

beforeEach(function (): void {
    $this->client = new RanetraceApiClient('test-api-key');
});

// ============================================
// searchErrors Tests
// ============================================

test('searchErrors sends GET request with no params', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response([
            'errors' => [],
            'meta' => ['total_count' => 0],
        ], 200),
    ]);

    $result = $this->client->searchErrors();

    expect($result['success'])->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), 'api.ranetrace.com/v1/errors');
    });
});

test('searchErrors sends type parameter', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response(['errors' => [], 'meta' => []], 200),
    ]);

    $this->client->searchErrors(['type' => 'php']);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'type=php');
    });
});

test('searchErrors sends status parameter', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response(['errors' => [], 'meta' => []], 200),
    ]);

    $this->client->searchErrors(['status' => 'open']);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'status=open');
    });
});

test('searchErrors sends environments array', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response(['errors' => [], 'meta' => []], 200),
    ]);

    $this->client->searchErrors(['environments' => ['production', 'staging']]);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'environments');
    });
});

test('searchErrors sends date filter parameters', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response(['errors' => [], 'meta' => []], 200),
    ]);

    $this->client->searchErrors([
        'first_occurred_period' => '24h',
        'last_occurred_from' => '2026-01-01T00:00:00+00:00',
    ]);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'first_occurred_period=24h')
            && str_contains($request->url(), 'last_occurred_from');
    });
});

test('searchErrors sends pagination parameters', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response(['errors' => [], 'meta' => []], 200),
    ]);

    $this->client->searchErrors(['limit' => 50, 'cursor' => 'abc123']);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'limit=50')
            && str_contains($request->url(), 'cursor=abc123');
    });
});

test('searchErrors sends include_archived parameter', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response(['errors' => [], 'meta' => []], 200),
    ]);

    $this->client->searchErrors(['include_archived' => true]);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'include_archived');
    });
});

test('searchErrors returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->searchErrors();

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

test('searchErrors handles API error response', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response(['error' => 'Validation failed'], 422),
    ]);

    $result = $this->client->searchErrors(['min_occurrences' => -1]);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(422);
});

test('searchErrors includes correct headers', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors*' => Http::response(['errors' => [], 'meta' => []], 200),
    ]);

    $this->client->searchErrors();

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/MCP/1.0')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Ranetrace-API-Version', '1.0')
            && $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

// ============================================
// restoreError Tests
// ============================================

test('restoreError sends POST request to correct endpoint', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/restore' => Http::response([
            'error' => ['id' => 'err_123', 'state' => 'open'],
            'activity' => ['id' => 1, 'action' => 'restored'],
        ], 200),
    ]);

    $result = $this->client->restoreError('123');

    expect($result['success'])->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.ranetrace.com/v1/errors/123/restore';
    });
});

test('restoreError sends type parameter', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/restore' => Http::response(['error' => [], 'activity' => []], 200),
    ]);

    $this->client->restoreError('123', 'javascript');

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'javascript';
    });
});

test('restoreError defaults to php type', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/restore' => Http::response(['error' => [], 'activity' => []], 200),
    ]);

    $this->client->restoreError('123');

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'php';
    });
});

test('restoreError returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->restoreError('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

test('restoreError handles 404 response', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/999/restore' => Http::response(['error' => 'Error not found'], 404),
    ]);

    $result = $this->client->restoreError('999');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

test('restoreError includes correct headers', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/restore' => Http::response(['error' => [], 'activity' => []], 200),
    ]);

    $this->client->restoreError('123');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/MCP/1.0')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Ranetrace-API-Version', '1.0')
            && $request->hasHeader('Content-Type', 'application/json');
    });
});

// ============================================
// bulkRestoreErrors Tests
// ============================================

test('bulkRestoreErrors sends POST request to correct endpoint', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/restore' => Http::response([
            'restored_count' => 2,
            'errors' => [
                ['id' => 'err_123', 'state' => 'open'],
                ['id' => 'err_124', 'state' => 'open'],
            ],
        ], 200),
    ]);

    $result = $this->client->bulkRestoreErrors(['123', '124']);

    expect($result['success'])->toBeTrue();
    expect($result['data']['restored_count'])->toBe(2);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.ranetrace.com/v1/errors/bulk/restore'
            && $request['error_ids'] === ['123', '124']
            && $request['type'] === 'php';
    });
});

test('bulkRestoreErrors sends type parameter', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/restore' => Http::response(['restored_count' => 1, 'errors' => []], 200),
    ]);

    $this->client->bulkRestoreErrors(['123'], 'javascript');

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'javascript';
    });
});

test('bulkRestoreErrors defaults to php type', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/restore' => Http::response(['restored_count' => 1, 'errors' => []], 200),
    ]);

    $this->client->bulkRestoreErrors(['123']);

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'php';
    });
});

test('bulkRestoreErrors returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->bulkRestoreErrors(['123']);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

test('bulkRestoreErrors handles API error response', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/restore' => Http::response(['error' => 'Some errors not found'], 404),
    ]);

    $result = $this->client->bulkRestoreErrors(['123', '999']);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

test('bulkRestoreErrors sends multiple error IDs correctly', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/restore' => Http::response(['restored_count' => 3, 'errors' => []], 200),
    ]);

    $this->client->bulkRestoreErrors(['1', '2', '3']);

    Http::assertSent(function (Request $request) {
        return $request['error_ids'] === ['1', '2', '3'];
    });
});
