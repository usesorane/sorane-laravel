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
// createNote Tests
// ============================================

test('createNote sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes' => Http::response([
            'note' => [
                'id' => 'note_456',
                'error_id' => 'err_123',
                'body' => 'Test note content',
                'author_name' => 'AI Agent',
                'created_at' => '2026-01-23T12:34:56+00:00',
            ],
        ], 201),
    ]);

    $result = $this->client->createNote('123', ['body' => 'Test note content']);

    expect($result['success'])->toBeTrue();
    expect($result['status'])->toBe(201);
    expect($result['data']['note']['id'])->toBe('note_456');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/notes'
            && $request->method() === 'POST'
            && $request['body'] === 'Test note content'
            && $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});

test('createNote returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->createNote('123', ['body' => 'Test']);

    expect($result['success'])->toBeFalse();
    expect($result['error'] ?? null)->toBe(missingMcpTokenMessage());
});

test('createNote handles 404 error when error not found', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/999/notes' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Error not found'],
        ], 404),
    ]);

    $result = $this->client->createNote('999', ['body' => 'Test']);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

test('createNote handles 403 forbidden error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes' => Http::response([
            'error' => ['code' => 'forbidden', 'message' => 'Access denied'],
        ], 403),
    ]);

    $result = $this->client->createNote('123', ['body' => 'Test']);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(403);
});

test('createNote handles 422 validation error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes' => Http::response([
            'message' => 'The body field is required',
            'errors' => ['body' => ['The body field is required']],
        ], 422),
    ]);

    $result = $this->client->createNote('123', ['body' => '']);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(422);
});

test('createNote handles connection exception', function (): void {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $result = $this->client->createNote('123', ['body' => 'Test']);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Connection refused');
});

// ============================================
// listNotes Tests
// ============================================

test('listNotes retrieves notes for an error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes*' => Http::response([
            'notes' => [
                [
                    'id' => 'note_456',
                    'body' => 'First note',
                    'author_name' => 'AI Agent',
                    'created_at' => '2026-01-23T12:34:56+00:00',
                ],
                [
                    'id' => 'note_457',
                    'body' => 'Second note',
                    'author_name' => 'John Doe',
                    'created_at' => '2026-01-23T12:35:56+00:00',
                ],
            ],
            'meta' => ['total' => 2, 'limit' => 50, 'offset' => 0],
        ], 200),
    ]);

    $result = $this->client->listNotes('123');

    expect($result['success'])->toBeTrue();
    expect($result['data']['notes'])->toHaveCount(2);
    expect($result['data']['meta']['total'])->toBe(2);
});

test('listNotes sends query parameters correctly', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes*' => Http::response([
            'notes' => [],
            'meta' => ['total' => 0],
        ], 200),
    ]);

    $this->client->listNotes('123', [
        'limit' => 10,
        'offset' => 5,
        'author' => 'ai',
    ]);

    Http::assertSent(function (Request $request) {
        $query = [];
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), 'api.ranetrace.com/v1/errors/123/notes')
            && $query['limit'] === '10'
            && $query['offset'] === '5'
            && $query['author'] === 'ai';
    });
});

test('listNotes returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->listNotes('123');

    expect($result['success'])->toBeFalse();
    expect($result['error'] ?? null)->toBe(missingMcpTokenMessage());
});

test('listNotes handles 404 when error not found', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/999/notes*' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Error not found'],
        ], 404),
    ]);

    $result = $this->client->listNotes('999');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

// ============================================
// getNote Tests
// ============================================

test('getNote retrieves a specific note', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/456' => Http::response([
            'note' => [
                'id' => 'note_456',
                'error_id' => 'err_123',
                'body' => 'Detailed investigation notes',
                'author_name' => 'AI Agent',
                'created_at' => '2026-01-23T12:34:56+00:00',
                'updated_at' => '2026-01-23T12:34:56+00:00',
                'archived' => false,
            ],
        ], 200),
    ]);

    $result = $this->client->getNote('123', '456');

    expect($result['success'])->toBeTrue();
    expect($result['data']['note']['id'])->toBe('note_456');
    expect($result['data']['note']['body'])->toBe('Detailed investigation notes');
});

test('getNote returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->getNote('123', '456');

    expect($result['success'])->toBeFalse();
    expect($result['error'] ?? null)->toBe(missingMcpTokenMessage());
});

test('getNote handles 404 when note not found', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/999' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Note not found'],
        ], 404),
    ]);

    $result = $this->client->getNote('123', '999');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

// ============================================
// updateNote Tests
// ============================================

test('updateNote sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/456' => Http::response([
            'note' => [
                'id' => 'note_456',
                'error_id' => 'err_123',
                'body' => 'Updated note content',
                'author_name' => 'AI Agent',
                'created_at' => '2026-01-23T12:34:56+00:00',
                'updated_at' => '2026-01-23T12:35:00+00:00',
            ],
        ], 200),
    ]);

    $result = $this->client->updateNote('123', '456', ['body' => 'Updated note content']);

    expect($result['success'])->toBeTrue();
    expect($result['data']['note']['body'])->toBe('Updated note content');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/notes/456'
            && $request->method() === 'PUT'
            && $request['body'] === 'Updated note content';
    });
});

test('updateNote returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->updateNote('123', '456', ['body' => 'Test']);

    expect($result['success'])->toBeFalse();
    expect($result['error'] ?? null)->toBe(missingMcpTokenMessage());
});

test('updateNote handles 403 when trying to update others note', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/456' => Http::response([
            'error' => ['code' => 'forbidden', 'message' => 'Cannot modify notes created by other users'],
        ], 403),
    ]);

    $result = $this->client->updateNote('123', '456', ['body' => 'Test']);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(403);
});

test('updateNote handles 404 when note not found', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/999' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Note not found'],
        ], 404),
    ]);

    $result = $this->client->updateNote('123', '999', ['body' => 'Test']);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

// ============================================
// deleteNote Tests
// ============================================

test('deleteNote sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/456' => Http::response([
            'message' => 'Note archived successfully',
        ], 200),
    ]);

    $result = $this->client->deleteNote('123', '456');

    expect($result['success'])->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/notes/456'
            && $request->method() === 'DELETE';
    });
});

test('deleteNote returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->deleteNote('123', '456');

    expect($result['success'])->toBeFalse();
    expect($result['error'] ?? null)->toBe(missingMcpTokenMessage());
});

test('deleteNote handles 403 when trying to delete others note', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/456' => Http::response([
            'error' => ['code' => 'forbidden', 'message' => 'Cannot delete notes created by other users'],
        ], 403),
    ]);

    $result = $this->client->deleteNote('123', '456');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(403);
});

test('deleteNote handles 404 when note not found', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/999' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Note not found'],
        ], 404),
    ]);

    $result = $this->client->deleteNote('123', '999');

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

// ============================================
// createNotesBulk Tests
// ============================================

test('createNotesBulk sends correct request to API', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/bulk' => Http::response([
            'notes' => [
                [
                    'id' => 'note_456',
                    'body' => 'First note',
                    'author_name' => 'AI Agent',
                    'created_at' => '2026-01-23T12:34:56+00:00',
                ],
                [
                    'id' => 'note_457',
                    'body' => 'Second note',
                    'author_name' => 'AI Agent',
                    'created_at' => '2026-01-23T12:34:56+00:00',
                ],
            ],
        ], 201),
    ]);

    $result = $this->client->createNotesBulk('123', [
        'notes' => [
            ['body' => 'First note'],
            ['body' => 'Second note'],
        ],
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['status'])->toBe(201);
    expect($result['data']['notes'])->toHaveCount(2);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/123/notes/bulk'
            && $request->method() === 'POST'
            && count($request['notes']) === 2;
    });
});

test('createNotesBulk returns the MCP token guidance when no token is configured', function (): void {
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);
    $result = $client->createNotesBulk('123', ['notes' => [['body' => 'Test']]]);

    expect($result['success'])->toBeFalse();
    expect($result['error'] ?? null)->toBe(missingMcpTokenMessage());
});

test('createNotesBulk handles 422 validation error', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes/bulk' => Http::response([
            'message' => 'Validation failed',
            'errors' => ['notes.0.body' => ['The body field is required']],
        ], 422),
    ]);

    $result = $this->client->createNotesBulk('123', ['notes' => [['body' => '']]]);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(422);
});

test('createNotesBulk handles 404 when error not found', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/999/notes/bulk' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Error not found'],
        ], 404),
    ]);

    $result = $this->client->createNotesBulk('999', ['notes' => [['body' => 'Test']]]);

    expect($result['success'])->toBeFalse();
    expect($result['status'])->toBe(404);
});

// ============================================
// Retry Logic Tests
// ============================================

test('note methods retry on 5xx errors', function (): void {
    $attemptCount = 0;

    Http::fake(function () use (&$attemptCount) {
        $attemptCount++;
        if ($attemptCount < 3) {
            return Http::response(['error' => 'Server error'], 500);
        }

        return Http::response(['note' => ['id' => 'note_456']], 200);
    });

    $result = $this->client->getNote('123', '456');

    expect($attemptCount)->toBe(3);
    expect($result['success'])->toBeTrue();
});

test('note methods include correct headers', function (): void {
    Http::fake([
        'api.ranetrace.com/v1/errors/123/notes' => Http::response(['note' => []], 201),
    ]);

    $this->client->createNote('123', ['body' => 'Test']);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/MCP/1.0')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Ranetrace-API-Version', '1.0')
            && $request->hasHeader('Content-Type', 'application/json');
    });
});
