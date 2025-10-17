<?php

declare(strict_types=1);

namespace Sorane\Laravel\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Sorane\Laravel\Jobs\HandleLogJob;
use Sorane\Laravel\Utilities\DataSanitizer;
use Throwable;

class SoraneLogHandler extends AbstractProcessingHandler
{
    public function __construct(string|int $level = 'DEBUG', bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    /**
     * Writes the record down to the log of the implementing handler
     */
    protected function write(LogRecord $record): void
    {
        // Skip if Sorane is not enabled globally
        if (! config('sorane.enabled', false)) {
            return;
        }

        // Skip if logging is not enabled
        if (! config('sorane.logging.enabled', false)) {
            return;
        }

        // Skip if the channel should be excluded
        $excludedChannels = config('sorane.logging.excluded_channels', []);
        $channelName = $record->channel ?? 'default';
        if (in_array($channelName, $excludedChannels)) {
            return;
        }

        // Prepare log data for Sorane API
        // Limit field sizes to stay within API 5MB request limit
        $message = $record->message;
        if (mb_strlen($message) > 50000) {
            $message = mb_substr($message, 0, 50000).'... (truncated)';
        }

        $context = DataSanitizer::sanitizeForSerialization($record->context);
        $contextJson = json_encode($context);
        if (mb_strlen($contextJson) > 51200) { // 50KB
            $context = ['_truncated' => 'Context exceeded 50KB limit and was removed'];
        }

        $extra = DataSanitizer::sanitizeForSerialization(array_merge($record->extra, [
            'environment' => config('app.env'),
            'laravel_version' => app()->version(),
            'php_version' => phpversion(),
        ]));
        $extraJson = json_encode($extra);
        if (mb_strlen($extraJson) > 10240) { // 10KB
            $extra = ['_truncated' => 'Extra data exceeded 10KB limit and was removed'];
        }

        $logData = [
            'level' => mb_strtolower($record->level->name),
            'message' => $message,
            'context' => $context,
            'channel' => $channelName,
            'timestamp' => $record->datetime->format('c'), // ISO 8601 format
            'extra' => $extra,
        ];

        try {
            // Send via queue by default, or synchronously if queue is disabled
            if (config('sorane.logging.queue', true)) {
                HandleLogJob::dispatch($logData);
            } else {
                HandleLogJob::dispatchSync($logData);
            }
        } catch (Throwable $e) {
            // Prevent infinite loops by using sorane_internal channel
            try {
                Log::channel('sorane_internal')->warning('Failed to queue log to Sorane: '.$e->getMessage());
            } catch (Throwable) {
                // Silent failure if channel not configured
            }
        }
    }
}
