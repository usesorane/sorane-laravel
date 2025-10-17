<?php

declare(strict_types=1);

namespace Sorane\Laravel\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Centralized internal logging for Sorane package.
 *
 * Prevents infinite loops by using dedicated sorane_internal channel
 * with fallback to stderr if channel is unavailable.
 */
class InternalLogger
{
    /**
     * Log an error message.
     *
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * Log an info message.
     *
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * Log a critical message.
     *
     * @param  array<string, mixed>  $context
     */
    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    /**
     * Log a debug message.
     *
     * @param  array<string, mixed>  $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * Internal logging implementation with fallback.
     *
     * @param  array<string, mixed>  $context
     */
    protected static function log(string $level, string $message, array $context = []): void
    {
        // Check if internal logging is disabled
        if (! config('sorane.internal_logging.enabled', true)) {
            return;
        }

        try {
            // Try logging to sorane_internal channel
            Log::channel('sorane_internal')->$level($message, $context);
        } catch (Throwable $e) {
            // Fallback to stderr if channel fails
            self::logToStderr($level, $message, $context, $e);
        }
    }

    /**
     * Fallback logging to stderr when channel is unavailable.
     *
     * @param  array<string, mixed>  $context
     */
    protected static function logToStderr(string $level, string $message, array $context, Throwable $channelError): void
    {
        // Check if stderr fallback is enabled
        if (! config('sorane.internal_logging.stderr_fallback', true)) {
            return;
        }

        try {
            $formattedContext = empty($context) ? '' : ' | Context: '.json_encode($context);
            $channelErrorMsg = ' | Channel Error: '.$channelError->getMessage();

            error_log(sprintf(
                '[Sorane Internal %s] %s%s%s',
                mb_strtoupper($level),
                $message,
                $formattedContext,
                $channelErrorMsg
            ));
        } catch (Throwable) {
            // Absolute silence as last resort
            // If we can't even write to stderr, there's nothing more we can do
        }
    }
}
