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
class GetCertificateStatusTool extends Tool
{
    use FormatsUntrustedText;
    use PresentsMonitorFinding;

    /**
     * The tool's description.
     */
    protected string $description = 'Certificate and HTTPS health for this website: the verdict first (what we found, why it matters, what to do), then the raw data behind it — whether HTTPS is served, the negotiated protocol, the issuer, the validity window, the signed days until expiry (negative once lapsed), and the domains the certificate covers. Takes no parameters — the MCP token already scopes the call to one website.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $result = $this->client->getCertificateStatus();

        if (! ($result['success'] ?? false)) {
            return $this->monitorFailure($result, 'certificate status');
        }

        $data = $result['data'] ?? [];
        $certificate = is_array($data['certificate'] ?? null) ? $data['certificate'] : null;

        $output = "# Certificate\n\n".$this->untrustedTextNotice()."\n\n";
        $output .= $this->findingSection(is_array($data['finding'] ?? null) ? $data['finding'] : null);

        if ($certificate === null) {
            $output .= "\nThis website's certificate has not been checked yet.\n";

            return Response::text($output.$this->metaSection(is_array($data['meta'] ?? null) ? $data['meta'] : []));
        }

        $output .= "\n## Certificate\n";
        $output .= $this->line('HTTPS supported', $certificate['https_supported'] ?? null);
        $output .= $this->untrustedLine('Protocol family', $certificate['protocol_family'] ?? null);
        $output .= $this->untrustedLine('Protocol', $certificate['protocol'] ?? null);
        $output .= $this->untrustedLine('Issuer', $certificate['issuer'] ?? null);
        $output .= $this->untrustedLine('Signature algorithm', $certificate['signature_algorithm'] ?? null);
        $output .= $this->untrustedLine('Serial', $certificate['serial'] ?? null);
        $output .= $this->untrustedLine('Fingerprint', $certificate['fingerprint'] ?? null);
        $output .= $this->line('Valid from', $certificate['valid_from'] ?? null);
        $output .= $this->line('Valid until', $certificate['valid_until'] ?? null);
        $output .= $this->line('Days until expiry', $certificate['days_until_expiry'] ?? null);
        $output .= $this->line('Last checked at', $certificate['last_checked_at'] ?? null);

        $output .= $this->coveredDomains(is_array($certificate['covered_domains'] ?? null) ? $certificate['covered_domains'] : []);

        $output .= $this->metaSection(is_array($data['meta'] ?? null) ? $data['meta'] : []);

        return Response::text($output);
    }

    /**
     * The domains the certificate covers. They come from the presented
     * certificate rather than from Ranetrace, so they are untrusted text.
     *
     * @param  array<int, mixed>  $domains
     */
    protected function coveredDomains(array $domains): string
    {
        if ($domains === []) {
            return "\n## Covered domains\nNone reported.\n";
        }

        $output = "\n## Covered domains\n";

        foreach ($domains as $domain) {
            $output .= '- '.$this->formatUntrustedText($this->nonEmptyString($domain) ?? 'unknown')."\n";
        }

        return $output;
    }
}
