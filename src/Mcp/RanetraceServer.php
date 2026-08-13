<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp;

use Laravel\Mcp\Server;
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

class RanetraceServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * Declared as a constant because it is read from outside the server too:
     * {@see \Ranetrace\Laravel\RanetraceServiceProvider} contextually binds a
     * `RanetraceApiClient` carrying the MCP token to exactly these classes. One
     * list means a tool added here cannot quietly miss that binding and fall
     * back to the ingest key.
     *
     * @var array<int, class-string<Server\Tool>>
     */
    public const array TOOLS = [
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

    /**
     * The MCP server's name.
     */
    protected string $name = 'Ranetrace';

    /**
     * The MCP server's implementation version. This is the version reported
     * over the MCP protocol to the connecting client — it advertises the
     * server's capabilities and is intentionally independent of the package's
     * Composer release version (which a downstream client doesn't see).
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = 'Ranetrace MCP server for the application and the website it monitors. '
        ."Errors: search and investigate with advanced filtering, get error details and statistics, keep investigation notes, and control error states (resolve, ignore, snooze, delete, restore).\n\n"
        .'Monitors: get-monitor-status-tool answers "which of my monitors needs a look" for the website — every enabled monitor with its verdict — and the per-monitor tools (uptime, performance, Lighthouse, certificate, domain, broken links) give the detail behind one of them. '
        .'Every monitor tool answers verdict first: what we found, why it matters, what to do, the same guidance a human reads on the dashboard, with the raw measurements following as its evidence. Read the verdict before the numbers, and pass its wording on rather than re-deriving your own conclusion from the data. '
        .'A monitor that is switched off is reported as disabled rather than answered with stale figures. '
        .'The monitor tools take no parameters: the MCP token scopes every call to one website.';

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Server\Tool>>
     */
    protected array $tools = self::TOOLS;
}
