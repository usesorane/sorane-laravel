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
class GetUptimeStatusTool extends Tool
{
    use FormatsUntrustedText;
    use PresentsMonitorFinding;

    /**
     * The tool's description.
     */
    protected string $description = 'Availability for this website: the verdict first (what we found, why it matters, what to do), then the raw data behind it — up or down right now, the 24-hour uptime percentage, and the recent outages. The outage list is what tells one long outage apart from a site that drops out every hour. Takes no parameters — the MCP token already scopes the call to one website.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $result = $this->client->getUptimeStatus();

        if (! ($result['success'] ?? false)) {
            return $this->monitorFailure($result, 'uptime status');
        }

        $data = $result['data'] ?? [];
        $uptime = is_array($data['uptime'] ?? null) ? $data['uptime'] : [];
        $periods = is_array($data['recent_down_periods'] ?? null) ? $data['recent_down_periods'] : [];

        $output = "# Uptime\n\n".$this->untrustedTextNotice()."\n\n";
        $output .= $this->findingSection(is_array($data['finding'] ?? null) ? $data['finding'] : null);

        $output .= "\n## Availability\n";
        $output .= $this->line('State', $uptime['state'] ?? null);
        $output .= $this->line('Uptime (last 24h)', isset($uptime['uptime_percentage_24h']) ? $uptime['uptime_percentage_24h'].'%' : null);
        $output .= $this->line('Down since', $uptime['down_since'] ?? null);
        $output .= $this->line('Down for', $uptime['down_duration'] ?? null);

        $output .= "\n## Recent outages\n";

        if ($periods === []) {
            $output .= "None recorded.\n";
        }

        foreach ($periods as $period) {
            if (! is_array($period)) {
                continue;
            }

            $started = $this->nonEmptyString($period['started_at'] ?? null) ?? 'unknown';
            $ended = $this->nonEmptyString($period['ended_at'] ?? null) ?? 'still down';
            $duration = $this->nonEmptyString($period['duration'] ?? null) ?? 'unknown';

            $output .= "- {$started} → {$ended} ({$duration})\n";
        }

        $output .= $this->metaSection(is_array($data['meta'] ?? null) ? $data['meta'] : []);

        return Response::text($output);
    }
}
