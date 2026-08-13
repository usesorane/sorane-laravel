<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Services\RanetraceApiClient;

test('getLatestErrors sends GET request to errors endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->getLatestErrors();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/errors'
            && $request->method() === 'GET';
    });
});

test('getLatestErrors passes query parameters', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->getLatestErrors([
        'limit' => 20,
        'environment' => 'production',
        'type' => 'exception',
    ]);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'limit=20')
            && str_contains($request->url(), 'environment=production')
            && str_contains($request->url(), 'type=exception');
    });
});

test('getLatestErrors returns success response format', function (): void {
    Http::fake([
        '*' => Http::response([
            'errors' => [
                ['id' => 'err-1', 'message' => 'Test error'],
            ],
        ], 200),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->getLatestErrors();

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($result['data']['errors'])->toHaveCount(1);
});

test('getError sends GET request with error id', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->getError('err-abc-123');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/err-abc-123'
            && $request->method() === 'GET';
    });
});

test('getError returns success response format', function (): void {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'id' => 'err-123',
                'message' => 'Test error',
                'stack_trace' => 'Error at line 1',
            ],
        ], 200),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->getError('err-123');

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($result['data']['error']['id'])->toBe('err-123');
});

test('getError returns 404 status for not found', function (): void {
    Http::fake([
        '*' => Http::response(['error' => 'Not found'], 404),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->getError('nonexistent');

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(404);
});

test('getErrorStats sends GET request to stats endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->getErrorStats();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/stats'
            && $request->method() === 'GET';
    });
});

test('getErrorStats passes period parameter', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->getErrorStats(['period' => '7d']);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'period=7d');
    });
});

test('getErrorStats returns success response format', function (): void {
    Http::fake([
        '*' => Http::response([
            'stats' => [
                'total_errors' => 100,
                'unique_errors' => 25,
                'resolved_errors' => 10,
            ],
        ], 200),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->getErrorStats(['period' => '24h']);

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($result['data']['stats']['total_errors'])->toBe(100);
});

test('MCP methods include authorization header', function (string $method, array $args): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key-auth');

    $client->{$method}(...$args);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer test-key-auth');
    });
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods send correct user agent header', function (string $method, array $args): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->{$method}(...$args);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/MCP/1.0');
    });
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods return error when api key is missing', function (string $method, array $args): void {
    Http::fake();
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);

    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe(missingMcpTokenMessage());
    Http::assertNothingSent();
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods handle network exceptions', function (string $method, array $args): void {
    Http::fake(function (): void {
        throw new Exception('Network failure');
    });

    $client = new RanetraceApiClient('test-key');
    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Network failure');
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods retry on 5xx server errors and succeed', function (string $method, array $args): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        if ($attempts < 2) {
            return Http::response(['error' => 'Server error'], 500);
        }

        return Http::response(['data' => 'success'], 200);
    });

    $client = new class('test-key') extends RanetraceApiClient
    {
        protected function sleep(int $milliseconds): void
        {
            // Skip sleep in tests
        }
    };

    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($attempts)->toBe(2);
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods return server error after max retries', function (string $method, array $args): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::response(['error' => 'Server error'], 500);
    });

    $client = new class('test-key') extends RanetraceApiClient
    {
        protected function sleep(int $milliseconds): void
        {
            // Skip sleep in tests
        }
    };

    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(500)
        ->and($attempts)->toBe(3);
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods retry on connection exception and succeed', function (string $method, array $args): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        if ($attempts < 2) {
            throw new Illuminate\Http\Client\ConnectionException('Connection timed out');
        }

        return Http::response(['data' => 'success'], 200);
    });

    $client = new class('test-key') extends RanetraceApiClient
    {
        protected function sleep(int $milliseconds): void
        {
            // Skip sleep in tests
        }
    };

    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($attempts)->toBe(2);
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods return error after max connection retries', function (string $method, array $args): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts): void {
        $attempts++;
        throw new Illuminate\Http\Client\ConnectionException('Connection timed out');
    });

    $client = new class('test-key') extends RanetraceApiClient
    {
        protected function sleep(int $milliseconds): void
        {
            // Skip sleep in tests
        }
    };

    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Connection timed out')
        ->and($attempts)->toBe(3);
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods do not retry on 4xx client errors', function (string $method, array $args): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::response(['error' => 'Bad request'], 400);
    });

    $client = new class('test-key') extends RanetraceApiClient
    {
        protected function sleep(int $milliseconds): void
        {
            // Skip sleep in tests
        }
    };

    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(400)
        ->and($attempts)->toBe(1);
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods handle malformed JSON response', function (string $method, array $args): void {
    Http::fake([
        '*' => Http::response('not valid json', 200, ['Content-Type' => 'text/plain']),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(200)
        ->and($result['data'])->toBe([])
        ->and($result['error'])->toBe('Invalid response format');
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('calculateBackoff returns exponential delays', function (): void {
    $client = new class('test-key') extends RanetraceApiClient
    {
        public function testBackoff(int $attempt): int
        {
            return $this->calculateBackoff($attempt);
        }
    };

    expect($client->testBackoff(1))->toBe(100)
        ->and($client->testBackoff(2))->toBe(200)
        ->and($client->testBackoff(3))->toBe(400);
});
