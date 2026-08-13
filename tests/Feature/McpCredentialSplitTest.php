<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request;
use Ranetrace\Laravel\Mcp\RanetraceServer;
use Ranetrace\Laravel\Mcp\Tools\GetMonitorStatusTool;
use Ranetrace\Laravel\Mcp\Tools\LatestErrorsTool;
use Ranetrace\Laravel\Services\RanetraceApiClient;

/**
 * Ranetrace uses two credentials that must never be confused: `ranetrace.key`
 * writes captured telemetry in, `ranetrace.mcp.token` reads data back out. The
 * MCP tools get the token through one contextual binding; everything else, the
 * batch worker above all, keeps the ingest key.
 */
beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    config([
        'ranetrace.key' => 'ingest-key-123',
        'ranetrace.mcp.token' => 'mcp-token-abc',
    ]);
});

test('an MCP tool resolved from the container authenticates with the MCP token', function (): void {
    Http::fake();

    app(GetMonitorStatusTool::class)->handle(new Request([]));

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer mcp-token-abc'));
});

test('the batch client keeps authenticating with the ingest key', function (): void {
    Http::fake();

    app(RanetraceApiClient::class)->sendErrorBatch([['message' => 'boom']]);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ingest-key-123'));
});

test('the two clients send different credentials', function (): void {
    Http::fake();

    app(GetMonitorStatusTool::class)->handle(new Request([]));
    app(RanetraceApiClient::class)->sendErrorBatch([['message' => 'boom']]);

    $sent = Http::recorded()
        ->map(fn (array $pair): string => (string) $pair[0]->header('Authorization')[0])
        ->all();

    expect($sent)->toHaveCount(2)
        ->and($sent[0])->not->toBe($sent[1]);
});

test('every registered tool is bound to the MCP token, not just the monitor ones', function (): void {
    // The binding is driven by the server's own tool list, so a tool added
    // there can never quietly fall back to the ingest key.
    foreach (RanetraceServer::TOOLS as $tool) {
        $client = (new ReflectionClass($tool))
            ->getProperty('client');
        $client->setAccessible(true);

        $apiKey = (new ReflectionClass(RanetraceApiClient::class))->getProperty('apiKey');
        $apiKey->setAccessible(true);

        expect($apiKey->getValue($client->getValue(app($tool))))->toBe('mcp-token-abc');
    }
});

test('a tool resolved without an MCP token does not fall back to the ingest key', function (): void {
    config(['ranetrace.mcp.token' => null]);

    $client = (new ReflectionClass(LatestErrorsTool::class))->getProperty('client');
    $client->setAccessible(true);

    $apiKey = (new ReflectionClass(RanetraceApiClient::class))->getProperty('apiKey');
    $apiKey->setAccessible(true);

    expect($apiKey->getValue($client->getValue(app(LatestErrorsTool::class))))
        ->not->toBe('ingest-key-123');
});

test('no MCP token means no MCP server is registered', function (): void {
    $this->configOverrides = [
        'ranetrace.key' => 'ingest-key-123',
        'ranetrace.mcp.token' => null,
    ];
    $this->reloadApplication();

    expect(Mcp::getLocalServer('ranetrace'))->toBeNull();
});

test('an MCP token registers the server even without an ingest key', function (): void {
    // A developer machine can legitimately have only the read credential.
    $this->configOverrides = [
        'ranetrace.key' => null,
        'ranetrace.mcp.token' => 'mcp-token-abc',
    ];
    $this->reloadApplication();

    expect(Mcp::getLocalServer('ranetrace'))->not->toBeNull();
});

test('disabling MCP still wins over a configured token', function (): void {
    $this->configOverrides = [
        'ranetrace.mcp.enabled' => false,
        'ranetrace.mcp.token' => 'mcp-token-abc',
    ];
    $this->reloadApplication();

    expect(Mcp::getLocalServer('ranetrace'))->toBeNull();
});
