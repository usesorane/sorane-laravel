<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Services\RanetraceApiClient;

/**
 * The monitor read methods and the two API error codes every MCP tool branches
 * on. The error-code handling lives in the client's shared response path, so it
 * is asserted once here rather than in each of the seven tools.
 */
dataset('monitor endpoints', [
    'status' => ['getMonitorStatus', 'https://api.ranetrace.com/v1/monitors/status'],
    'uptime' => ['getUptimeStatus', 'https://api.ranetrace.com/v1/monitors/uptime/latest'],
    'performance' => ['getPerformanceStats', 'https://api.ranetrace.com/v1/monitors/performance/latest'],
    'lighthouse' => ['getLighthouseAudit', 'https://api.ranetrace.com/v1/monitors/lighthouse/latest'],
    'certificate' => ['getCertificateStatus', 'https://api.ranetrace.com/v1/monitors/certificate/latest'],
    'domain' => ['getDomainStatus', 'https://api.ranetrace.com/v1/monitors/domain/latest'],
    'broken links' => ['getBrokenLinks', 'https://api.ranetrace.com/v1/monitors/broken-links/latest'],
]);

test('monitor read methods GET their endpoint with the MCP token', function (string $method, string $url): void {
    Http::fake();
    $client = new RanetraceApiClient('mcp-token');

    $client->{$method}();

    Http::assertSent(function ($request) use ($url): bool {
        return $request->url() === $url
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer mcp-token');
    });
})->with('monitor endpoints');

test('monitor read methods return the decoded payload', function (string $method): void {
    Http::fake([
        '*' => Http::response([
            'finding' => ['severity' => 'warning', 'is_problem' => true],
            'meta' => ['website_id' => 7],
        ], 200),
    ]);

    $result = (new RanetraceApiClient('mcp-token'))->{$method}();

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($result['data']['finding']['severity'])->toBe('warning');
})->with('monitor endpoints');

test('a monitor read without a token explains how to get one', function (string $method): void {
    Http::fake();

    $result = (new RanetraceApiClient(''))->{$method}();

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('/mcp page in Ranetrace')
        ->and($result['error'])->toContain('RANETRACE_MCP_TOKEN');

    Http::assertNothingSent();
})->with('monitor endpoints');

test('a 403 MCP_TOKEN_REQUIRED tells the agent to create a token and where to put it', function (): void {
    Http::fake([
        '*' => Http::response([
            'success' => false,
            'message' => "This endpoint requires an MCP token with the mcp:monitors ability. Create one on the website's /mcp page in Ranetrace.",
            'error_code' => 'MCP_TOKEN_REQUIRED',
        ], 403),
    ]);

    $result = (new RanetraceApiClient('ingest-key'))->getMonitorStatus();

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(403)
        ->and($result['error_code'])->toBe('MCP_TOKEN_REQUIRED')
        ->and($result['error'])->toContain('mcp:monitors')
        ->and($result['error'])->toContain('/mcp page in Ranetrace')
        ->and($result['error'])->toContain('RANETRACE_MCP_TOKEN');
});

test('MCP_TOKEN_REQUIRED still names the /mcp page when the API message does not', function (): void {
    Http::fake([
        '*' => Http::response([
            'success' => false,
            'message' => 'Token missing the required ability.',
            'error_code' => 'MCP_TOKEN_REQUIRED',
        ], 403),
    ]);

    $result = (new RanetraceApiClient('ingest-key'))->getUptimeStatus();

    expect($result['error'])->toContain('Token missing the required ability.')
        ->and($result['error'])->toContain('/mcp page in Ranetrace')
        ->and($result['error'])->toContain('RANETRACE_MCP_TOKEN');
});

test('MCP_TOKEN_REQUIRED is surfaced on the error tools too, not just the monitor ones', function (): void {
    Http::fake([
        '*' => Http::response([
            'success' => false,
            'message' => "This endpoint requires an MCP token with the mcp:errors ability. Create one on the website's /mcp page in Ranetrace.",
            'error_code' => 'MCP_TOKEN_REQUIRED',
        ], 403),
    ]);

    $result = (new RanetraceApiClient('ingest-key'))->getLatestErrors();

    expect($result['error_code'])->toBe('MCP_TOKEN_REQUIRED')
        ->and($result['error'])->toContain('mcp:errors')
        ->and($result['error'])->toContain('RANETRACE_MCP_TOKEN');
});

test('a 409 MONITOR_DISABLED is surfaced verbatim', function (): void {
    Http::fake([
        '*' => Http::response([
            'success' => false,
            'message' => 'Lighthouse monitoring is disabled for this website.',
            'error_code' => 'MONITOR_DISABLED',
        ], 409),
    ]);

    $result = (new RanetraceApiClient('mcp-token'))->getLighthouseAudit();

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(409)
        ->and($result['error_code'])->toBe('MONITOR_DISABLED')
        ->and($result['error'])->toBe('Lighthouse monitoring is disabled for this website.');
});

test('any other failure surfaces the API message rather than nothing', function (): void {
    Http::fake([
        '*' => Http::response([
            'success' => false,
            'message' => 'Subscription required.',
            'error_code' => 'SUBSCRIPTION_REQUIRED',
        ], 402),
    ]);

    $result = (new RanetraceApiClient('mcp-token'))->getDomainStatus();

    expect($result['error'])->toBe('Subscription required.')
        ->and($result['error_code'])->toBe('SUBSCRIPTION_REQUIRED');
});
