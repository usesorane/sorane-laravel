<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Utilities;

use Ranetrace\Php\Support\Scrubber;

/**
 * Lets the shared payload builders redact through THIS package's scrubber.
 *
 * TEMPORARY. Slice C of the shared-core migration merges the two scrubbers, at
 * which point the builders take `Ranetrace\Php\Support\SecretScrubber` directly
 * and this class is deleted.
 *
 * It exists because the two are not yet interchangeable in one specific way.
 * {@see SecretScrubber::scrubDeep()} here resolves the secret-bearing segments
 * of every URL-shaped value it finds by matching that URL against the ROUTER,
 * per value. The framework-agnostic scrubber has no router, so it takes one
 * pre-resolved list of segment values from its caller and applies it to all of
 * them. Handing the builders the core scrubber today would therefore weaken
 * redaction inside log context, event properties and JS breadcrumbs, which is
 * exactly the direction a refactor must never move. So the `$sensitiveValues`
 * argument is accepted and deliberately ignored: the router already answers the
 * question more precisely.
 */
final class CoreScrubber implements Scrubber
{
    public function scrubString(string $value): string
    {
        return SecretScrubber::scrubString($value);
    }

    public function scrubUrl(?string $url): ?string
    {
        return SecretScrubber::scrubUrl($url);
    }

    /**
     * @param  array<int, string>|null  $sensitiveValues
     */
    public function scrubUrlPath(?string $url, ?array $sensitiveValues = null): ?string
    {
        return SecretScrubber::scrubUrlPath($url, $sensitiveValues ?? []);
    }

    /**
     * @param  array<int, string>|null  $sensitiveValues  Ignored: see the class docblock. The route table is the more precise oracle and is consulted per URL.
     */
    public function scrubDeep(mixed $data, ?array $sensitiveValues = null): mixed
    {
        return SecretScrubber::scrubDeep($data);
    }
}
