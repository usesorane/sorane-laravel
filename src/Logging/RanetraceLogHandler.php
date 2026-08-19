<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\DataSanitizer;
use Ranetrace\Laravel\Utilities\PayloadSizer;
use Ranetrace\Laravel\Utilities\SecretScrubber;
use Throwable;

/**
 * Per-field caps below bound the size of a SINGLE captured log item. They do
 * NOT by themselves keep a 1000-item batch under the API's 5MB request limit —
 * worst case is far larger (see logs.md). Batch fit is guaranteed by the
 * pre-flight size guard in SendBatchToRanetraceJob::trimToByteBudget(). NOT
 * user-tunable: raising any of these widens per-item size and the 413 risk.
 */
class RanetraceLogHandler extends AbstractProcessingHandler
{
    private const string TRUNCATION_SUFFIX = '... (truncated)';

    private const int MAX_MESSAGE_LENGTH = 50_000;

    private const int MAX_CONTEXT_BYTES = 51_200; // 50 KB

    private const int MAX_EXTRA_BYTES = 10_240; // 10 KB

    /**
     * Writes the record down to the log of the implementing handler.
     *
     * The entire body is wrapped in try/catch: this handler sits in the host
     * application's `Log::error(...)` path, so it MUST never throw back into
     * the caller's business code. (Failure-isolation Core Rule.)
     */
    protected function write(LogRecord $record): void
    {
        try {
            // Skip if Ranetrace is not enabled globally
            if (! config('ranetrace.enabled', true)) {
                return;
            }

            // Skip if logging is not enabled
            if (! config('ranetrace.logging.enabled', false)) {
                return;
            }

            // Skip if the channel should be excluded
            $channel = $record->channel;
            $excludedChannels = config('ranetrace.logging.excluded_channels', []);
            if (in_array($channel, $excludedChannels, true)) {
                return;
            }

            // Scrub key=value secrets BEFORE truncation, so a secret can't
            // survive by being split across the length boundary.
            $message = SecretScrubber::scrubString($record->message);
            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX)).self::TRUNCATION_SUFFIX;
            }

            // Sanitize for serialization, redact secrets (by sensitive key name,
            // plus tokens inside URL-shaped string values), then cap size
            // (replacing mid-structure rather than truncating, since partial
            // JSON is invalid).
            $context = PayloadSizer::capBytes(
                SecretScrubber::scrubDeep(DataSanitizer::sanitizeForSerialization($record->context)),
                self::MAX_CONTEXT_BYTES,
                'Context exceeded 50KB limit and was removed'
            );

            // Cap only the user-supplied extra, then always attach the small,
            // known-safe environment metadata. This way triage metadata
            // survives even when the user extra is dropped wholesale for being
            // oversized.
            $userExtra = PayloadSizer::capBytes(
                SecretScrubber::scrubDeep(DataSanitizer::sanitizeForSerialization($record->extra)),
                self::MAX_EXTRA_BYTES,
                'Extra data exceeded 10KB limit and was removed'
            );

            $extra = array_merge((array) $userExtra, [
                'environment' => config('app.env'),
                'php_version' => phpversion(),
                // The generic pair shared with the PHP SDK's extra vocabulary;
                // the legacy `laravel_version` spelling is retired, matching
                // the error payload's move.
                'framework' => 'laravel',
                'framework_version' => app()->version(),
            ]);

            $logData = [
                'level' => mb_strtolower($record->level->name),
                'message' => $message,
                'context' => $context,
                'channel' => $channel,
                'timestamp' => $record->datetime->format('c'), // ISO 8601
                'extra' => $extra,
            ];

            if (config('ranetrace.logging.queue', true)) {
                HandleLogJob::dispatch($logData);
            } else {
                HandleLogJob::dispatchSync($logData);
            }
        } catch (Throwable $e) {
            // Fail silently — the handler must never propagate exceptions into
            // the host's logging call site. Diagnose via the internal channel.
            InternalLogger::warning('Failed to capture log to Ranetrace', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
