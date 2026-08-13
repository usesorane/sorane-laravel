<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;

/**
 * Shared presentation for the monitor MCP tools.
 *
 * Every monitor endpoint answers in the same shape — a `finding` verdict, then
 * the data it was built from, then `meta` — because that is the product's
 * stance: an agent should read what we found, why it matters and what to do
 * before it reads a single number. Rendering that verdict in one place keeps
 * all seven tools saying it identically, and keeps the API's two branch-worthy
 * error codes mapped identically too.
 */
trait PresentsMonitorFinding
{
    use FormatsUntrustedText;

    /**
     * The verdict block: the same what-we-found / why-it-matters / what-to-do
     * guidance a human reads in the detail-page banner.
     *
     * A finding with null strings is Ranetrace's "nothing measured yet" state,
     * which is a real answer rather than a gap — it is spelled out so an agent
     * does not read the empty verdict as a broken response.
     *
     * @param  array<string, mixed>|null  $finding
     */
    protected function findingSection(?array $finding): string
    {
        $severity = is_string($finding['severity'] ?? null) ? $finding['severity'] : 'unknown';
        $isProblem = ($finding['is_problem'] ?? false) ? 'yes' : 'no';

        $output = "## Verdict\n**Severity:** {$severity}\n**Needs a look:** {$isProblem}\n";

        $found = $this->nonEmptyString($finding['found'] ?? null);
        $why = $this->nonEmptyString($finding['why'] ?? null);
        $fix = $this->nonEmptyString($finding['fix'] ?? null);
        $headline = $this->nonEmptyString($finding['headline'] ?? null);

        if ($found === null && $why === null && $fix === null) {
            return $output."\nNothing has been measured for this monitor yet, so there is no verdict to report.\n";
        }

        if ($headline !== null) {
            $output .= '**Headline:** '.$this->formatUntrustedText($headline)."\n";
        }

        if ($found !== null) {
            $output .= "\n**What we found:** ".$this->formatUntrustedText($found)."\n";
        }

        if ($why !== null) {
            $output .= "\n**Why it matters:** ".$this->formatUntrustedText($why)."\n";
        }

        if ($fix !== null) {
            $output .= "\n**What to do:** ".$this->formatUntrustedText($fix)."\n";
        }

        return $output;
    }

    /**
     * The trailing block naming which website was answered about and when.
     *
     * @param  array<string, mixed>|null  $meta
     */
    protected function metaSection(?array $meta): string
    {
        $url = $this->nonEmptyString($meta['url'] ?? null);
        $checkedAt = $this->nonEmptyString($meta['checked_at'] ?? null);

        $output = "\n## Website\n";
        $output .= '**URL:** '.($url === null ? 'unknown' : $this->formatUntrustedText($url))."\n";
        $output .= '**Answered at:** '.($checkedAt ?? 'unknown')."\n";

        return $output;
    }

    /**
     * Map an API failure to a user-facing error response.
     *
     * `MONITOR_DISABLED` and `MCP_TOKEN_REQUIRED` are surfaced as the client
     * composed them — one is a true answer about the account, the other is a
     * setup fix — so neither is buried under a generic "request failed".
     *
     * @param  array<string, mixed>  $result
     */
    protected function monitorFailure(array $result, string $subject): Response
    {
        $message = $this->nonEmptyString($result['error'] ?? null) ?? 'Unknown error occurred';
        $errorCode = $result['error_code'] ?? null;

        if ($errorCode === 'MONITOR_DISABLED' || $errorCode === 'MCP_TOKEN_REQUIRED') {
            return Response::error($message);
        }

        return Response::error("Failed to fetch {$subject}: {$message}");
    }

    /**
     * A "key: value" line whose value is untrusted, or a dash when absent.
     */
    protected function untrustedLine(string $label, mixed $value): string
    {
        $string = $this->nonEmptyString($value);

        return "**{$label}:** ".($string === null ? '—' : $this->formatUntrustedText($string))."\n";
    }

    /**
     * A "key: value" line for a value Ranetrace itself produced (a number, a
     * state word, a timestamp), or a dash when absent.
     */
    protected function line(string $label, mixed $value): string
    {
        if ($value === null || $value === '') {
            return "**{$label}:** —\n";
        }

        if (is_bool($value)) {
            return "**{$label}:** ".($value ? 'yes' : 'no')."\n";
        }

        return "**{$label}:** ".(is_scalar($value) ? (string) $value : '—')."\n";
    }

    /**
     * The value as a non-empty string, or null. Keeps "absent", "null" and ""
     * as one case so every caller renders them the same way.
     */
    protected function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return $value === '' ? null : $value;
    }
}
