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
class GetBrokenLinksTool extends Tool
{
    use FormatsUntrustedText;
    use PresentsMonitorFinding;

    /**
     * The tool's description.
     */
    protected string $description = 'The broken links found by this website\'s latest completed site audit: the verdict first (what we found, why it matters, what to do), then the raw data behind it — the audit\'s link counts and the broken links themselves, each with the page it was found on. Internal links are listed first because they are the ones to fix first. Takes no parameters — the MCP token already scopes the call to one website.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $result = $this->client->getBrokenLinks();

        if (! ($result['success'] ?? false)) {
            return $this->monitorFailure($result, 'broken links');
        }

        $data = $result['data'] ?? [];
        $audit = is_array($data['audit'] ?? null) ? $data['audit'] : null;
        $links = is_array($data['broken_links'] ?? null) ? $data['broken_links'] : [];
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        $output = "# Broken links\n\n".$this->untrustedTextNotice()."\n\n";
        $output .= $this->findingSection(is_array($data['finding'] ?? null) ? $data['finding'] : null);

        if ($audit === null) {
            $output .= "\nNo completed site audit has been stored for this website yet.\n";

            return Response::text($output.$this->metaSection($meta));
        }

        $output .= "\n## Audit\n";
        $output .= $this->line('Audited at', $audit['audited_at'] ?? null);
        $output .= $this->line('Triggered by', $audit['triggered_by'] ?? null);
        $output .= $this->line('Links checked', $audit['links_checked'] ?? null);
        $output .= $this->line('Links broken', $audit['links_broken'] ?? null);
        $output .= $this->line('Broken internal', $audit['links_broken_internal'] ?? null);
        $output .= $this->line('Broken external', $audit['links_broken_external'] ?? null);

        $output .= "\n## Broken links\n";

        if ($links === []) {
            $output .= "None.\n";
        }

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $output .= $this->linkLine($link);
        }

        if (($meta['truncated'] ?? false) === true) {
            $output .= "\nThis list is capped: the audit found more broken links than are shown. The counts above are the full totals.\n";
        }

        $output .= $this->metaSection($meta);

        return Response::text($output);
    }

    /**
     * One broken link: the URL, the status it answered with, and the page it
     * was found on. Both URLs are authored by the crawled site rather than by
     * Ranetrace, so both are treated as untrusted text.
     *
     * @param  array<string, mixed>  $link
     */
    protected function linkLine(array $link): string
    {
        $url = $this->formatUntrustedText($this->nonEmptyString($link['url'] ?? null) ?? 'unknown');
        $status = is_numeric($link['status_code'] ?? null) ? (string) $link['status_code'] : 'no response';
        $scope = ($link['is_internal'] ?? false) ? 'internal' : 'external';
        $foundOn = $this->formatUntrustedText($this->nonEmptyString($link['found_on'] ?? null) ?? 'unknown');
        $type = $this->nonEmptyString($link['type'] ?? null);

        $line = "- {$url} ({$scope}, status {$status}";

        if ($type !== null) {
            $line .= ', '.$this->formatUntrustedText($type);
        }

        return $line.") found on {$foundOn}\n";
    }
}
