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
class GetMonitorStatusTool extends Tool
{
    use FormatsUntrustedText;
    use PresentsMonitorFinding;

    /**
     * The tool's description.
     */
    protected string $description = 'Answer "which of my monitors needs a look" for this website: every enabled monitor with its verdict (what we found, why it matters, what to do), the same guidance a human reads on the Ranetrace dashboard. Start here, then use the per-monitor tool for the detail behind a verdict. Takes no parameters — the MCP token already scopes the call to one website.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $result = $this->client->getMonitorStatus();

        if (! ($result['success'] ?? false)) {
            return $this->monitorFailure($result, 'monitor status');
        }

        $data = $result['data'] ?? [];
        $monitors = is_array($data['monitors'] ?? null) ? $data['monitors'] : [];
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        $output = "# Monitor status\n\n".$this->untrustedTextNotice()."\n\n";

        if ($monitors === []) {
            $output .= "No monitor is currently enabled for this website.\n";
        }

        foreach ($monitors as $monitor) {
            if (! is_array($monitor)) {
                continue;
            }

            $name = $this->nonEmptyString($monitor['monitor'] ?? null) ?? 'unknown';
            $finding = is_array($monitor['finding'] ?? null) ? $monitor['finding'] : null;

            $output .= "## {$name}\n";
            // The per-monitor block reuses the shared verdict rendering but
            // drops its own heading — the monitor name above already is one.
            $output .= $this->stripVerdictHeading($this->findingSection($finding))."\n";
        }

        $disabled = is_array($meta['disabled'] ?? null) ? $meta['disabled'] : [];

        if ($disabled !== []) {
            $names = array_map(fn (mixed $name): string => $this->nonEmptyString($name) ?? 'unknown', $disabled);

            $output .= "\n## Disabled monitors\n";
            $output .= 'Not running, so they have no verdict: '.implode(', ', $names).".\n";
            $output .= "Their stored measurements are frozen at whatever the site looked like when monitoring was paused, so asking their detail tool returns an error rather than a stale answer.\n";
        }

        $output .= $this->metaSection($meta);

        return Response::text($output);
    }

    /**
     * Drop the shared "## Verdict" heading from a rendered finding block.
     *
     * On this tool the monitor name is already the heading, and a second one
     * under every monitor would bury the seven verdicts an agent is scanning.
     */
    protected function stripVerdictHeading(string $section): string
    {
        return str_replace("## Verdict\n", '', $section);
    }
}
