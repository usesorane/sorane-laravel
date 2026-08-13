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
class GetDomainStatusTool extends Tool
{
    use FormatsUntrustedText;
    use PresentsMonitorFinding;

    /**
     * The tool's description.
     */
    protected string $description = 'Domain registration health for this website: the verdict first (what we found, why it matters, what to do), then the raw data behind it — the registrar, when the registration lapses, the signed days until expiry (negative once lapsed), DNSSEC, and the registrar locks. Each lock is a tri-state: yes, no, or unknown when the registry did not say. Takes no parameters — the MCP token already scopes the call to one website.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $result = $this->client->getDomainStatus();

        if (! ($result['success'] ?? false)) {
            return $this->monitorFailure($result, 'domain status');
        }

        $data = $result['data'] ?? [];
        $domain = is_array($data['domain'] ?? null) ? $data['domain'] : null;

        $output = "# Domain\n\n".$this->untrustedTextNotice()."\n\n";
        $output .= $this->findingSection(is_array($data['finding'] ?? null) ? $data['finding'] : null);

        if ($domain === null) {
            $output .= "\nThis website's domain registration has not been looked up yet.\n";

            return Response::text($output.$this->metaSection(is_array($data['meta'] ?? null) ? $data['meta'] : []));
        }

        $locks = is_array($domain['locks'] ?? null) ? $domain['locks'] : [];

        $output .= "\n## Registration\n";
        $output .= $this->untrustedLine('Domain', $domain['domain'] ?? null);
        $output .= $this->untrustedLine('Registrar', $domain['registrar'] ?? null);
        $output .= $this->line('Registered at', $domain['registered_at'] ?? null);
        $output .= $this->line('Expires at', $domain['expires_at'] ?? null);
        $output .= $this->line('Days until expiry', $domain['days_until_expiry'] ?? null);
        $output .= $this->line('Last changed at', $domain['last_changed_at'] ?? null);
        $output .= $this->triState('DNSSEC enabled', $domain['dnssec_enabled'] ?? null);

        $output .= "\n## Registrar locks\n";
        $output .= $this->triState('Transfer lock', $locks['transfer'] ?? null);
        $output .= $this->triState('Delete lock', $locks['delete'] ?? null);
        $output .= $this->triState('Update lock', $locks['update'] ?? null);

        $output .= "\n## Lookup\n";
        $output .= $this->triState('TLD supported', $domain['tld_supported'] ?? null);
        $output .= $this->line('Last checked at', $domain['last_checked_at'] ?? null);
        $output .= $this->line('Last check failed', $domain['last_check_failed'] ?? null);
        $output .= $this->untrustedLine('Last error', $domain['last_error_message'] ?? null);

        $output .= $this->metaSection(is_array($data['meta'] ?? null) ? $data['meta'] : []);

        return Response::text($output);
    }

    /**
     * A tri-state flag. "Unknown" is a distinct answer from "no" here: the
     * registry not saying whether a lock is set is not the same as the lock
     * being absent, and Ranetrace deliberately stays quiet on the difference
     * rather than reporting a missing lock it cannot see.
     */
    protected function triState(string $label, mixed $value): string
    {
        if ($value === null) {
            return "**{$label}:** unknown (the registry did not say)\n";
        }

        return $this->line($label, (bool) $value);
    }
}
