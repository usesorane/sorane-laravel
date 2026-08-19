<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Ranetrace\Php\Support\Diagnostics;

/**
 * Points the shared core's diagnostics at this package's own sink.
 *
 * `ranetrace/ranetrace-php` writes its diagnostics to a daily file under its
 * buffer directory, which is right for a plain PHP host and wrong here: a
 * Laravel application already has the dedicated `ranetrace_internal` channel,
 * and an operator looking for "why did that item get dropped" looks there. The
 * shared builders take the interface, so this hands them ours.
 *
 * The isolation rule is the same on both sides: this channel must never be one
 * the host routed back into Ranetrace, or a failing send would log a failure
 * that is captured, buffered, sent, and fails again.
 */
final class CoreDiagnostics implements Diagnostics
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function debug(string $message, array $context = []): void
    {
        InternalLogger::debug($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        InternalLogger::info($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function notice(string $message, array $context = []): void
    {
        InternalLogger::notice($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        InternalLogger::warning($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        InternalLogger::error($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function critical(string $message, array $context = []): void
    {
        InternalLogger::critical($message, $context);
    }
}
