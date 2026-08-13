<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ranetrace\Laravel\Mcp\Tools\Concerns\FormatsUntrustedText;
use Ranetrace\Laravel\Mcp\Tools\Concerns\PresentsMonitorFinding;
use Ranetrace\Laravel\Services\RanetraceApiClient;

#[IsReadOnly]
class GetPerformanceStatsTool extends Tool
{
    use FormatsUntrustedText;
    use PresentsMonitorFinding;

    /**
     * The tool's description.
     */
    protected string $description = 'Response-time health for this website: the verdict first (what we found, why it matters, what to do), then the raw data behind it — the 24-hour average response time and the connection breakdown showing where that time goes (DNS, TCP, TLS, server processing, data transfer). Takes no parameters — the MCP token already scopes the call to one website.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $result = $this->client->getPerformanceStats();

        if (! ($result['success'] ?? false)) {
            return $this->monitorFailure($result, 'performance stats');
        }

        $data = $result['data'] ?? [];
        $performance = is_array($data['performance'] ?? null) ? $data['performance'] : [];
        $breakdown = is_array($performance['connection_breakdown_ms_24h'] ?? null)
            ? $performance['connection_breakdown_ms_24h']
            : [];

        $output = "# Performance\n\n".$this->untrustedTextNotice()."\n\n";
        $output .= $this->findingSection(is_array($data['finding'] ?? null) ? $data['finding'] : null);

        $output .= "\n## Response time (last 24h)\n";
        $output .= $this->line('Average', $this->milliseconds($performance['average_response_time_ms_24h'] ?? null));

        $output .= "\n## Where the time goes (24h averages)\n";
        $output .= $this->line('DNS lookup', $this->milliseconds($breakdown['dns'] ?? null));
        $output .= $this->line('TCP connection', $this->milliseconds($breakdown['tcp'] ?? null));
        $output .= $this->line('TLS handshake', $this->milliseconds($breakdown['ssl'] ?? null));
        $output .= $this->line('Server processing', $this->milliseconds($breakdown['server_processing'] ?? null));
        $output .= $this->line('Data transfer', $this->milliseconds($breakdown['data_transfer'] ?? null));

        $output .= $this->metaSection(is_array($data['meta'] ?? null) ? $data['meta'] : []);

        return Response::text($output);
    }

    /**
     * A timing figure with its unit, keeping "not measured" as null so the
     * shared line renderer shows a dash rather than a misleading "0 ms".
     */
    protected function milliseconds(mixed $value): ?string
    {
        return is_numeric($value) ? $value.' ms' : null;
    }
}
