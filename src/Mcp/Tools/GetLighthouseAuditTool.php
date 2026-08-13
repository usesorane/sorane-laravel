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
class GetLighthouseAuditTool extends Tool
{
    use FormatsUntrustedText;
    use PresentsMonitorFinding;

    /**
     * The tool's description.
     */
    protected string $description = 'The latest Lighthouse audit for this website: the verdict first (what we found, why it matters, what to do), then the raw data behind it — the five category scores, the metrics behind the performance number, the previous run\'s scores for trend, and the improvement opportunities ranked by estimated time saved. Takes no parameters — the MCP token already scopes the call to one website.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $result = $this->client->getLighthouseAudit();

        if (! ($result['success'] ?? false)) {
            return $this->monitorFailure($result, 'the Lighthouse audit');
        }

        $data = $result['data'] ?? [];
        $audit = is_array($data['audit'] ?? null) ? $data['audit'] : null;
        $previous = is_array($data['previous'] ?? null) ? $data['previous'] : null;

        $output = "# Lighthouse\n\n".$this->untrustedTextNotice()."\n\n";
        $output .= $this->findingSection(is_array($data['finding'] ?? null) ? $data['finding'] : null);

        if ($audit === null) {
            $output .= "\nNo completed Lighthouse audit has been stored for this website yet.\n";

            return Response::text($output.$this->metaSection(is_array($data['meta'] ?? null) ? $data['meta'] : []));
        }

        $output .= "\n## Scores\n";
        $output .= $this->scoreLines(is_array($audit['scores'] ?? null) ? $audit['scores'] : []);
        $output .= $this->line('Form factor', $audit['form_factor'] ?? null);
        $output .= $this->untrustedLine('Audited URL', $audit['url'] ?? null);
        $output .= $this->line('Audited at', $audit['audited_at'] ?? null);

        if ($previous !== null) {
            $output .= "\n## Previous run (for trend)\n";
            $output .= $this->scoreLines(is_array($previous['scores'] ?? null) ? $previous['scores'] : []);
            $output .= $this->line('Audited at', $previous['audited_at'] ?? null);
        }

        $output .= "\n## Opportunities (ranked by estimated time saved)\n";
        $output .= $this->opportunityLines(is_array($audit['opportunities'] ?? null) ? $audit['opportunities'] : []);

        $output .= $this->metaSection(is_array($data['meta'] ?? null) ? $data['meta'] : []);

        return Response::text($output);
    }

    /**
     * The five category scores. Any of them can be null: a run can complete
     * with a category ungraded, and `pwa` is not reported by current
     * Lighthouse versions at all.
     *
     * @param  array<string, mixed>  $scores
     */
    protected function scoreLines(array $scores): string
    {
        $labels = [
            'performance' => 'Performance',
            'accessibility' => 'Accessibility',
            'best_practices' => 'Best practices',
            'seo' => 'SEO',
            'pwa' => 'PWA',
        ];

        $output = '';

        foreach ($labels as $key => $label) {
            $output .= $this->line($label, $scores[$key] ?? null);
        }

        return $output;
    }

    /**
     * The stored opportunities, in the order the API returned them (already
     * ranked by savings, and deliberately not re-sorted here so this tool and
     * the detail page cannot disagree about what the top opportunity is).
     *
     * Titles come from the audited page's own Lighthouse report, so they are
     * treated as untrusted text.
     *
     * @param  array<int, mixed>  $opportunities
     */
    protected function opportunityLines(array $opportunities): string
    {
        if ($opportunities === []) {
            return "None reported.\n";
        }

        $output = '';

        foreach ($opportunities as $opportunity) {
            if (! is_array($opportunity)) {
                continue;
            }

            $title = $this->formatUntrustedText($this->nonEmptyString($opportunity['title'] ?? null) ?? 'untitled');
            $savings = is_numeric($opportunity['savings_ms'] ?? null) ? $opportunity['savings_ms'].' ms' : 'unknown';
            $id = $this->formatUntrustedText($this->nonEmptyString($opportunity['id'] ?? null) ?? 'unknown');

            $output .= "- {$title} (saves ~{$savings}, audit id {$id})\n";
        }

        return $output;
    }
}
