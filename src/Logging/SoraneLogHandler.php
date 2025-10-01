<?php

declare(strict_types=1);

namespace Sorane\Laravel\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Sorane\Laravel\Jobs\SendLogToSoraneJob;
use Sorane\Laravel\Utilities\DataSanitizer;
use Throwable;

class SoraneLogHandler extends AbstractProcessingHandler
{
    public function __construct($level = 'DEBUG', bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    /**
     * Writes the record down to the log of the implementing handler
     */
    protected function write(LogRecord $record): void
    {
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
        $logData = [
            'level' => mb_strtolower($record->level->name),
            'message' => $record->message,
            'context' => DataSanitizer::sanitizeForSerialization($record->context),
            'channel' => $channelName,
            'timestamp' => $record->datetime->format('c'), // ISO 8601 format
            'extra' => DataSanitizer::sanitizeForSerialization(array_merge($record->extra, [
                'environment' => config('app.env'),
                'laravel_version' => app()->version(),
                'php_version' => phpversion(),
            ])),
        ];

        try {
            // Send via queue by default, or synchronously if queue is disabled
            if (config('sorane.logging.queue', true)) {
                SendLogToSoraneJob::dispatch($logData);
            } else {
                SendLogToSoraneJob::dispatchSync($logData);
            }
        } catch (Throwable $e) {
            // Prevent infinite loops by using a different logger
            Log::channel('single')->warning('Failed to queue log to Sorane: '.$e->getMessage());
        }
    }
}
