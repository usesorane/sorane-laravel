<?php

declare(strict_types=1);

use Laravel\Mcp\Server;
use Ranetrace\Laravel\Mcp\RanetraceServer;
use Ranetrace\Laravel\Mcp\Tools\BulkDeleteErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\BulkIgnoreErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\BulkReopenErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\BulkResolveErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\BulkRestoreErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\CreateNotesTool;
use Ranetrace\Laravel\Mcp\Tools\CreateNoteTool;
use Ranetrace\Laravel\Mcp\Tools\DeleteErrorTool;
use Ranetrace\Laravel\Mcp\Tools\DeleteNoteTool;
use Ranetrace\Laravel\Mcp\Tools\ErrorStatsTool;
use Ranetrace\Laravel\Mcp\Tools\GetBrokenLinksTool;
use Ranetrace\Laravel\Mcp\Tools\GetCertificateStatusTool;
use Ranetrace\Laravel\Mcp\Tools\GetDomainStatusTool;
use Ranetrace\Laravel\Mcp\Tools\GetErrorActivityTool;
use Ranetrace\Laravel\Mcp\Tools\GetErrorTool;
use Ranetrace\Laravel\Mcp\Tools\GetLighthouseAuditTool;
use Ranetrace\Laravel\Mcp\Tools\GetMonitorStatusTool;
use Ranetrace\Laravel\Mcp\Tools\GetNoteTool;
use Ranetrace\Laravel\Mcp\Tools\GetPerformanceStatsTool;
use Ranetrace\Laravel\Mcp\Tools\GetUptimeStatusTool;
use Ranetrace\Laravel\Mcp\Tools\IgnoreErrorTool;
use Ranetrace\Laravel\Mcp\Tools\LatestErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\ListNotesTool;
use Ranetrace\Laravel\Mcp\Tools\ReopenErrorTool;
use Ranetrace\Laravel\Mcp\Tools\ResolveErrorTool;
use Ranetrace\Laravel\Mcp\Tools\RestoreErrorTool;
use Ranetrace\Laravel\Mcp\Tools\SearchErrorsTool;
use Ranetrace\Laravel\Mcp\Tools\SnoozeErrorTool;
use Ranetrace\Laravel\Mcp\Tools\UnignoreErrorTool;
use Ranetrace\Laravel\Mcp\Tools\UnsnoozeErrorTool;
use Ranetrace\Laravel\Mcp\Tools\UpdateNoteTool;

beforeEach(function (): void {
    if (! class_exists(Server::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }
});

function getServerProperty(string $property): mixed
{
    return (new ReflectionClass(RanetraceServer::class))->getDefaultProperties()[$property];
}

test('extends Laravel MCP Server class', function (): void {
    expect(RanetraceServer::class)->toExtend(Server::class);
});

test('has correct name property', function (): void {
    expect(getServerProperty('name'))->toBe('Ranetrace');
});

test('has correct version property', function (): void {
    expect(getServerProperty('version'))->toBe('1.0.0');
});

test('has instructions property set', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toBeString()
        ->not->toBeEmpty()
        ->toContain('Ranetrace');
});

test('instructions mention notes functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('notes');
});

test('instructions mention error state functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('resolve');
});

test('instructions mention the monitor status question', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('get-monitor-status')
        ->toContain('needs a look');
});

test('instructions tell the agent the verdict comes before the data', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('verdict first')
        ->toContain('what we found')
        ->toContain('why it matters')
        ->toContain('what to do');
});

test('instructions mention search functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('search');
});

test('instructions mention restore functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('restore');
});

test('registers LatestErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(LatestErrorsTool::class);
});

test('registers GetErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(GetErrorTool::class);
});

test('registers ErrorStatsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ErrorStatsTool::class);
});

test('registers CreateNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(CreateNoteTool::class);
});

test('registers ListNotesTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ListNotesTool::class);
});

test('registers GetNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(GetNoteTool::class);
});

test('registers UpdateNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(UpdateNoteTool::class);
});

test('registers DeleteNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(DeleteNoteTool::class);
});

test('registers CreateNotesTool', function (): void {
    expect(getServerProperty('tools'))->toContain(CreateNotesTool::class);
});

test('registers ResolveErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ResolveErrorTool::class);
});

test('registers ReopenErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ReopenErrorTool::class);
});

test('registers IgnoreErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(IgnoreErrorTool::class);
});

test('registers UnignoreErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(UnignoreErrorTool::class);
});

test('registers SnoozeErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(SnoozeErrorTool::class);
});

test('registers UnsnoozeErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(UnsnoozeErrorTool::class);
});

test('registers DeleteErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(DeleteErrorTool::class);
});

test('registers GetErrorActivityTool', function (): void {
    expect(getServerProperty('tools'))->toContain(GetErrorActivityTool::class);
});

test('registers BulkResolveErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(BulkResolveErrorsTool::class);
});

test('registers BulkIgnoreErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(BulkIgnoreErrorsTool::class);
});

test('registers BulkDeleteErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(BulkDeleteErrorsTool::class);
});

test('registers SearchErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(SearchErrorsTool::class);
});

test('registers RestoreErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(RestoreErrorTool::class);
});

test('registers BulkRestoreErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(BulkRestoreErrorsTool::class);
});

test('registers the full expected tool list', function (): void {
    // Single source of truth so the count is never embedded in a test name.
    $expected = [
        LatestErrorsTool::class,
        SearchErrorsTool::class,
        GetErrorTool::class,
        ErrorStatsTool::class,
        CreateNoteTool::class,
        ListNotesTool::class,
        GetNoteTool::class,
        UpdateNoteTool::class,
        DeleteNoteTool::class,
        CreateNotesTool::class,
        ResolveErrorTool::class,
        ReopenErrorTool::class,
        IgnoreErrorTool::class,
        UnignoreErrorTool::class,
        SnoozeErrorTool::class,
        UnsnoozeErrorTool::class,
        DeleteErrorTool::class,
        RestoreErrorTool::class,
        GetErrorActivityTool::class,
        BulkResolveErrorsTool::class,
        BulkReopenErrorsTool::class,
        BulkIgnoreErrorsTool::class,
        BulkDeleteErrorsTool::class,
        BulkRestoreErrorsTool::class,
        GetMonitorStatusTool::class,
        GetUptimeStatusTool::class,
        GetPerformanceStatsTool::class,
        GetLighthouseAuditTool::class,
        GetCertificateStatusTool::class,
        GetDomainStatusTool::class,
        GetBrokenLinksTool::class,
    ];

    $actual = getServerProperty('tools');

    expect($actual)->toHaveCount(count($expected));

    foreach ($expected as $tool) {
        expect($actual)->toContain($tool);
    }
});

test('the registered tools are exactly the TOOLS constant the service provider binds against', function (): void {
    // The contextual MCP-token binding is driven by RanetraceServer::TOOLS. If
    // the property ever stopped mirroring the constant, a registered tool could
    // resolve the default client and authenticate with the ingest key instead.
    expect(getServerProperty('tools'))->toBe(RanetraceServer::TOOLS);
});
