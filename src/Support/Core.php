<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Ranetrace\Php\Support\FingerprintGenerator;
use Ranetrace\Php\Support\SecretScrubber;

/**
 * Builds the shared core's collaborators against this application.
 *
 * `ranetrace/ranetrace-php` owns the redaction rules and the fingerprint
 * derivation for both SDKs. Neither class knows anything about Laravel; both
 * read what they need from {@see CoreConfig}, which is `config/ranetrace.php`
 * plus the four things a framework-agnostic library has to be told. This is the
 * one place that pairing is written down, so no call site has to remember it.
 *
 * Built fresh per capture rather than bound as a singleton, for the same reason
 * {@see CoreConfig} is: `config()` is mutable at runtime, and a scrubber that
 * cached its fragment list at boot would keep applying a configuration the
 * application no longer has.
 */
final class Core
{
    public static function scrubber(): SecretScrubber
    {
        return new SecretScrubber(CoreConfig::make(), new CoreDiagnostics);
    }

    public static function fingerprints(): FingerprintGenerator
    {
        return new FingerprintGenerator(CoreConfig::make());
    }
}
