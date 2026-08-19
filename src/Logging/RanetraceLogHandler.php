<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\CoreScrubber;
use Ranetrace\Php\Logging\LogItemBuilder;
use Throwable;

/**
 * Captures Monolog records into the Ranetrace `logs` buffer.
 *
 * The six-key item, its per-field caps and the `_truncated` marker wording live
 * in `Ranetrace\Php\Logging\LogItemBuilder`, shared with
 * `ranetrace/ranetrace-php`. What stays here is the Laravel half: the config
 * gates, the excluded-channel check, and dispatching the capture job.
 */
class RanetraceLogHandler extends AbstractProcessingHandler
{
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
            $excludedChannels = config('ranetrace.logging.excluded_channels', []);
            if (in_array($record->channel, $excludedChannels, true)) {
                return;
            }

            $logData = (new LogItemBuilder(CoreConfig::make(), new CoreScrubber))->build(
                $record->level->name,
                $record->message,
                $record->context,
                $record->channel,
                $record->datetime->format('c'), // ISO 8601
                $record->extra,
            );

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
