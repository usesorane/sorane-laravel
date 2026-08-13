<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Services\RanetraceApiClient;

beforeEach(function (): void {
    $this->client = new RanetraceApiClient('test-api-key');
});

// ============================================
// resolveError Tests
// ============================================

test('resolveError sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/resolve' => Http::response([
            'error' => [
                'id' => 'err_123',
                'state' => 'resolved',
                'is_resolved' => true,
                'is_ignored' => false,
                'snooze_until' => null,
            ],
            'activity' => [
                'id' => 456,
                'action' => 'resolved',
                'performed_at' => '2026-01-23T12:34:56+00:00',
            ],
        ], 200),
    ]);

    $result = $this->client->resolveError('123');

    expect($result['success'])->toBeTrue();
    expect($result['status'])->toBe(200);
    expect($result['data']['error']['state'])->toBe('resolved');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/resolve'
            && $request->method() === 'POST'
            && $request['type'] === 'php'
            && $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

test('resolveError sends javascript type correctly', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/resolve' => Http::response([
            'error' => ['id' => 'err_123', 'state' => 'resolved'],
        ], 200),
    ]);

    $this->client->resolveError('123', 'javascript');

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'javascript';
    });
});

test('resolveError returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->resolveError('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

test('resolveError handles 404 error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/999/resolve' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Error not found'],
        ], 404),
    ]);

    $result = $this->client->resolveError('999');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

test('resolveError handles 403 forbidden error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/resolve' => Http::response([
            'error' => ['code' => 'forbidden', 'message' => 'Access denied'],
        ], 403),
    ]);

    $result = $this->client->resolveError('123');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(403);
});

// ============================================
// reopenError Tests
// ============================================

test('reopenError sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/reopen' => Http::response([
            'error' => [
                'id' => 'err_123',
                'state' => 'open',
                'is_resolved' => false,
            ],
            'activity' => ['id' => 457, 'action' => 'reopened'],
        ], 200),
    ]);

    $result = $this->client->reopenError('123');

    expect($result['success'])->toBeTrue();
    expect($result['data']['error']['state'])->toBe('open');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/reopen'
            && $request->method() === 'POST';
    });
});

test('reopenError returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->reopenError('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

// ============================================
// ignoreError Tests
// ============================================

test('ignoreError sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/ignore' => Http::response([
            'error' => [
                'id' => 'err_123',
                'state' => 'ignored',
                'is_ignored' => true,
            ],
            'activity' => ['id' => 458, 'action' => 'ignored'],
        ], 200),
    ]);

    $result = $this->client->ignoreError('123');

    expect($result['success'])->toBeTrue();
    expect($result['data']['error']['is_ignored'])->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/ignore'
            && $request->method() === 'POST';
    });
});

test('ignoreError returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->ignoreError('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

// ============================================
// unignoreError Tests
// ============================================

test('unignoreError sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/unignore' => Http::response([
            'error' => [
                'id' => 'err_123',
                'state' => 'open',
                'is_ignored' => false,
            ],
            'activity' => ['id' => 459, 'action' => 'unignored'],
        ], 200),
    ]);

    $result = $this->client->unignoreError('123');

    expect($result['success'])->toBeTrue();
    expect($result['data']['error']['is_ignored'])->toBeFalse();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/unignore'
            && $request->method() === 'POST';
    });
});

test('unignoreError returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->unignoreError('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

// ============================================
// snoozeError Tests
// ============================================

test('snoozeError sends correct request with duration', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/snooze' => Http::response([
            'error' => [
                'id' => 'err_123',
                'state' => 'snoozed',
                'snooze_until' => '2026-01-24T12:34:56+00:00',
            ],
            'activity' => ['id' => 460, 'action' => 'snoozed'],
        ], 200),
    ]);

    $result = $this->client->snoozeError('123', ['duration' => '24h']);

    expect($result['success'])->toBeTrue();
    expect($result['data']['error']['state'])->toBe('snoozed');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/snooze'
            && $request->method() === 'POST'
            && $request['duration'] === '24h'
            && $request['type'] === 'php';
    });
});

test('snoozeError sends correct request with until datetime', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/snooze' => Http::response([
            'error' => ['id' => 'err_123', 'state' => 'snoozed'],
        ], 200),
    ]);

    $this->client->snoozeError('123', ['until' => '2026-01-25T09:00:00+00:00']);

    Http::assertSent(function (Request $request) {
        return $request['until'] === '2026-01-25T09:00:00+00:00';
    });
});

test('snoozeError handles javascript type', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/snooze' => Http::response([
            'error' => ['id' => 'err_123', 'state' => 'snoozed'],
        ], 200),
    ]);

    $this->client->snoozeError('123', ['duration' => '1h'], 'javascript');

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'javascript';
    });
});

test('snoozeError returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->snoozeError('123', ['duration' => '24h']);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

test('snoozeError handles 422 validation error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/snooze' => Http::response([
            'message' => 'The until field must be a future date',
        ], 422),
    ]);

    $result = $this->client->snoozeError('123', ['until' => '2020-01-01T00:00:00+00:00']);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(422);
});

// ============================================
// unsnoozeError Tests
// ============================================

test('unsnoozeError sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/unsnooze' => Http::response([
            'error' => [
                'id' => 'err_123',
                'state' => 'open',
                'snooze_until' => null,
            ],
            'activity' => ['id' => 461, 'action' => 'unsnoozed'],
        ], 200),
    ]);

    $result = $this->client->unsnoozeError('123');

    expect($result['success'])->toBeTrue();
    expect($result['data']['error']['snooze_until'])->toBeNull();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/unsnooze'
            && $request->method() === 'POST';
    });
});

test('unsnoozeError returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->unsnoozeError('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

// ============================================
// deleteError Tests
// ============================================

test('deleteError sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123' => Http::response([
            'message' => 'Error archived successfully',
            'activity' => ['id' => 462, 'action' => 'archived'],
        ], 200),
    ]);

    $result = $this->client->deleteError('123');

    expect($result['success'])->toBeTrue();
    expect($result['data']['message'])->toBe('Error archived successfully');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123'
            && $request->method() === 'DELETE';
    });
});

test('deleteError sends javascript type correctly', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123' => Http::response([
            'message' => 'Error archived successfully',
        ], 200),
    ]);

    $this->client->deleteError('123', 'javascript');

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'javascript';
    });
});

test('deleteError returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->deleteError('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

test('deleteError handles 404 error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/999' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Error not found'],
        ], 404),
    ]);

    $result = $this->client->deleteError('999');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

// ============================================
// getErrorActivity Tests
// ============================================

test('getErrorActivity sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/activity*' => Http::response([
            'activities' => [
                [
                    'id' => 456,
                    'action' => 'resolved',
                    'causer_name' => 'AI Agent',
                    'performed_at' => '2026-01-23T12:34:56+00:00',
                ],
            ],
            'meta' => ['total' => 1, 'limit' => 50, 'offset' => 0],
        ], 200),
    ]);

    $result = $this->client->getErrorActivity('123');

    expect($result['success'])->toBeTrue();
    expect($result['data']['activities'])->toHaveCount(1);
    expect($result['data']['meta']['total'])->toBe(1);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'api.ranetrace.com/v1/errors/123/activity')
            && $request->method() === 'GET';
    });
});

test('getErrorActivity sends query parameters correctly', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/activity*' => Http::response([
            'activities' => [],
            'meta' => ['total' => 0],
        ], 200),
    ]);

    $this->client->getErrorActivity('123', ['limit' => 10, 'offset' => 5]);

    Http::assertSent(function (Request $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $query['limit'] === '10' && $query['offset'] === '5';
    });
});

test('getErrorActivity sends type parameter', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/activity*' => Http::response([
            'activities' => [],
            'meta' => [],
        ], 200),
    ]);

    $this->client->getErrorActivity('123', [], 'javascript');

    Http::assertSent(function (Request $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $query['type'] === 'javascript';
    });
});

test('getErrorActivity returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->getErrorActivity('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

test('getErrorActivity handles 404 error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/999/activity*' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Error not found'],
        ], 404),
    ]);

    $result = $this->client->getErrorActivity('999');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

// ============================================
// bulkResolveErrors Tests
// ============================================

test('bulkResolveErrors sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/resolve' => Http::response([
            'resolved_count' => 3,
            'errors' => [
                ['id' => 'err_123', 'state' => 'resolved'],
                ['id' => 'err_124', 'state' => 'resolved'],
                ['id' => 'err_125', 'state' => 'resolved'],
            ],
        ], 200),
    ]);

    $result = $this->client->bulkResolveErrors(['123', '124', '125']);

    expect($result['success'])->toBeTrue();
    expect($result['data']['resolved_count'])->toBe(3);
    expect($result['data']['errors'])->toHaveCount(3);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/bulk/resolve'
            && $request->method() === 'POST'
            && $request['error_ids'] === ['123', '124', '125']
            && $request['type'] === 'php';
    });
});

test('bulkResolveErrors sends javascript type', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/resolve' => Http::response([
            'resolved_count' => 1,
            'errors' => [],
        ], 200),
    ]);

    $this->client->bulkResolveErrors(['123'], 'javascript');

    Http::assertSent(function (Request $request) {
        return $request['type'] === 'javascript';
    });
});

test('bulkResolveErrors returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->bulkResolveErrors(['123']);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

test('bulkResolveErrors handles 422 validation error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/resolve' => Http::response([
            'message' => 'Too many error IDs provided',
        ], 422),
    ]);

    $result = $this->client->bulkResolveErrors(range(1, 100));

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(422);
});

// ============================================
// bulkReopenErrors Tests
// ============================================

test('bulkReopenErrors sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/reopen' => Http::response([
            'reopened_count' => 2,
            'errors' => [
                ['id' => 'err_123', 'state' => 'open'],
                ['id' => 'err_124', 'state' => 'open'],
            ],
        ], 200),
    ]);

    $result = $this->client->bulkReopenErrors(['123', '124']);

    expect($result['success'])->toBeTrue();
    expect($result['data']['reopened_count'])->toBe(2);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/bulk/reopen'
            && $request->method() === 'POST';
    });
});

test('bulkReopenErrors returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->bulkReopenErrors(['123']);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

// ============================================
// bulkIgnoreErrors Tests
// ============================================

test('bulkIgnoreErrors sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/ignore' => Http::response([
            'ignored_count' => 2,
            'errors' => [
                ['id' => 'err_123', 'state' => 'ignored'],
                ['id' => 'err_124', 'state' => 'ignored'],
            ],
        ], 200),
    ]);

    $result = $this->client->bulkIgnoreErrors(['123', '124']);

    expect($result['success'])->toBeTrue();
    expect($result['data']['ignored_count'])->toBe(2);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/bulk/ignore'
            && $request->method() === 'POST'
            && $request['error_ids'] === ['123', '124'];
    });
});

test('bulkIgnoreErrors returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->bulkIgnoreErrors(['123']);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

// ============================================
// bulkDeleteErrors Tests
// ============================================

test('bulkDeleteErrors sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/bulk/delete' => Http::response([
            'deleted_count' => 2,
            'errors' => [
                ['id' => 'err_123', 'state' => 'archived'],
                ['id' => 'err_124', 'state' => 'archived'],
            ],
        ], 200),
    ]);

    $result = $this->client->bulkDeleteErrors(['123', '124']);

    expect($result['success'])->toBeTrue();
    expect($result['data']['deleted_count'])->toBe(2);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/bulk/delete'
            && $request->method() === 'POST';
    });
});

test('bulkDeleteErrors returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->bulkDeleteErrors(['123']);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe(missingMcpTokenMessage());
});

// ============================================
// Retry Logic Tests
// ============================================

test('error action methods retry on 5xx errors', function (): void {
    $attemptCount = 0;

    Http::fake(function () use (&$attemptCount) {
        $attemptCount++;
        if ($attemptCount < 3) {
            return Http::response(['error' => 'Server error'], 500);
        }

        return Http::response(['error' => ['id' => 'err_123', 'state' => 'resolved']], 200);
    });

    $result = $this->client->resolveError('123');

    expect($attemptCount)->toBe(3);
    expect($result['success'])->toBeTrue();
});

test('error action methods include correct headers', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/resolve' => Http::response([
            'error' => [],
        ], 200),
    ]);

    $this->client->resolveError('123');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/MCP/1.0')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Ranetrace-API-Version', '1.0')
            && $request->hasHeader('Content-Type', 'application/json');
    });
});

test('error action methods handle connection exceptions', function (): void {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $result = $this->client->resolveError('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Connection refused');
});
