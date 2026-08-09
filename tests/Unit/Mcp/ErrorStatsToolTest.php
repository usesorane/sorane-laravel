<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Ranetrace\Laravel\Mcp\Tools\ErrorStatsTool;
use Ranetrace\Laravel\Services\RanetraceApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }
});

/**
 * Creates a mock API client that returns a successful response with given stats.
 *
 * @param  array<string, mixed>  $stats
 * @param  array<string, mixed>|null  $expectedParams
 */
function mockClientWithStats(array $stats, ?array $expectedParams = null): RanetraceApiClient
{
    $mockClient = Mockery::mock(RanetraceApiClient::class);
    $expectation = $mockClient->shouldReceive('getErrorStats')->once();

    if ($expectedParams !== null) {
        $expectation->with($expectedParams);
    }

    $expectation->andReturn([
        'success' => true,
        'data' => ['stats' => $stats],
    ]);

    return $mockClient;
}

/**
 * Executes a tool request and returns the response text content.
 *
 * @param  array<string, mixed>  $requestParams
 */
function executeToolRequest(RanetraceApiClient $client, array $requestParams = []): string
{
    $tool = new ErrorStatsTool($client);
    $response = $tool->handle(new Request($requestParams));

    return (string) $response->content();
}

test('returns formatted stats on success', function (): void {
    $client = mockClientWithStats([
        'total_errors' => 100,
        'unique_errors' => 25,
        'resolved_errors' => 10,
    ], []);

    $tool = new ErrorStatsTool($client);
    $response = $tool->handle(new Request([]));

    expect($response)->toBeInstanceOf(Response::class);

    $text = (string) $response->content();
    expect($text)->toContain('**Total Errors:** 100');
    expect($text)->toContain('**Unique Errors:** 25');
    expect($text)->toContain('**Resolved Errors:** 10');
});

test('returns error when api fails', function (): void {
    $mockClient = Mockery::mock(RanetraceApiClient::class);
    $mockClient->shouldReceive('getErrorStats')
        ->once()
        ->andReturn([
            'success' => false,
            'error' => 'API connection failed',
        ]);

    $text = executeToolRequest($mockClient);

    expect($text)->toContain('Failed to fetch error statistics');
    expect($text)->toContain('API connection failed');
});

test('returns no stats message when empty', function (): void {
    $client = mockClientWithStats([]);
    $text = executeToolRequest($client);

    expect($text)->toContain('No error statistics available');
});

test('filters null period parameter', function (): void {
    $client = mockClientWithStats(['total_errors' => 1], []);

    $tool = new ErrorStatsTool($client);
    $tool->handle(new Request(['period' => null]));
});

test('passes period parameter to client', function (): void {
    $client = mockClientWithStats(['total_errors' => 1], ['period' => '7d']);

    $tool = new ErrorStatsTool($client);
    $tool->handle(new Request(['period' => '7d']));
});

test('formats period label correctly', function (string $period, string $expectedLabel): void {
    $client = mockClientWithStats(['total_errors' => 50]);
    $text = executeToolRequest($client, ['period' => $period]);

    expect($text)->toContain($expectedLabel);
})->with([
    '1h period' => ['1h', 'Last Hour'],
    '24h period' => ['24h', 'Last 24 Hours'],
    '7d period' => ['7d', 'Last 7 Days'],
    '30d period' => ['30d', 'Last 30 Days'],
]);

test('uses default 24h period label when not specified', function (): void {
    $client = mockClientWithStats(['total_errors' => 50]);
    $text = executeToolRequest($client);

    expect($text)->toContain('Last 24 Hours');
});

test('includes total unique and resolved counts', function (): void {
    $client = mockClientWithStats([
        'total_errors' => 500,
        'unique_errors' => 75,
        'resolved_errors' => 30,
    ]);
    $text = executeToolRequest($client, ['period' => '7d']);

    expect($text)->toContain('**Total Errors:** 500');
    expect($text)->toContain('**Unique Errors:** 75');
    expect($text)->toContain('**Resolved Errors:** 30');
});

test('includes by_type breakdown when present', function (): void {
    $client = mockClientWithStats([
        'total_errors' => 100,
        'by_type' => [
            'exception' => 60,
            'javascript' => 30,
            'log' => 10,
        ],
    ]);
    $text = executeToolRequest($client);

    expect($text)->toContain('## By Type');
    expect($text)->toContain('- exception: 60');
    expect($text)->toContain('- javascript: 30');
    expect($text)->toContain('- log: 10');
});

test('includes by_environment breakdown when present', function (): void {
    $client = mockClientWithStats([
        'total_errors' => 100,
        'by_environment' => [
            'production' => 70,
            'staging' => 20,
            'local' => 10,
        ],
    ]);
    $text = executeToolRequest($client);

    expect($text)->toContain('## By Environment');
    expect($text)->toContain('- production: 70');
    expect($text)->toContain('- staging: 20');
    expect($text)->toContain('- local: 10');
});

test('includes trend with correct direction indicator', function (string $direction, string $arrow, int $percentage): void {
    $client = mockClientWithStats([
        'total_errors' => 100,
        'trend' => [
            'direction' => $direction,
            'percentage' => $percentage,
        ],
    ]);
    $text = executeToolRequest($client);

    expect($text)->toContain('## Trend');
    expect($text)->toContain($arrow);
    expect($text)->toContain($direction);

    if ($percentage > 0) {
        expect($text)->toContain("{$percentage}%");
    }
})->with([
    'up trend' => ['up', '↑', 25],
    'down trend' => ['down', '↓', 15],
    'stable trend' => ['stable', '→', 0],
]);

test('includes top_errors list when present', function (): void {
    $client = mockClientWithStats([
        'total_errors' => 100,
        'top_errors' => [
            ['message' => 'Database connection failed', 'count' => 50],
            ['message' => 'Null pointer exception', 'count' => 30],
            ['message' => 'API timeout', 'count' => 20],
        ],
    ]);
    $text = executeToolRequest($client);

    // The messages are end-user-authored, so they render as quoted literals.
    expect($text)->toContain('## Top Errors');
    expect($text)->toContain('1. "Database connection failed" (50 occurrences)');
    expect($text)->toContain('2. "Null pointer exception" (30 occurrences)');
    expect($text)->toContain('3. "API timeout" (20 occurrences)');
    expect($text)->toContain('untrusted end-user input');
});

test('has correct description property', function (): void {
    $mockClient = Mockery::mock(RanetraceApiClient::class);
    $tool = new ErrorStatsTool($mockClient);

    $reflection = new ReflectionProperty($tool, 'description');
    $description = $reflection->getValue($tool);

    expect($description)->toContain('Get error statistics');
});

test('returns error with unknown message when error field is missing', function (): void {
    $mockClient = Mockery::mock(RanetraceApiClient::class);
    $mockClient->shouldReceive('getErrorStats')
        ->once()
        ->andReturn(['success' => false]);

    $text = executeToolRequest($mockClient);

    expect($text)->toContain('Unknown error occurred');
});

test('handles stats directly without wrapper', function (): void {
    $mockClient = Mockery::mock(RanetraceApiClient::class);
    $mockClient->shouldReceive('getErrorStats')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'total_errors' => 200,
                'unique_errors' => 50,
            ],
        ]);

    $text = executeToolRequest($mockClient);

    expect($text)->toContain('**Total Errors:** 200');
    expect($text)->toContain('**Unique Errors:** 50');
});

test('handles default zero values for missing counts', function (): void {
    $client = mockClientWithStats(['total_errors' => 100]);
    $text = executeToolRequest($client);

    expect($text)->toContain('**Total Errors:** 100');
    expect($text)->toContain('**Unique Errors:** 0');
    expect($text)->toContain('**Resolved Errors:** 0');
});
