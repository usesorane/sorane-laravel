<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Sorane\Laravel\Jobs\SendLogToSoraneJob;

beforeEach(function (): void {
    Bus::fake();
    config([
        'sorane.logging.enabled' => true,
        'sorane.logging.queue' => true,
        'sorane.logging.queue_name' => 'default',
        'logging.channels.sorane' => [
            'driver' => 'sorane',
            'level' => 'debug',
        ],
    ]);
});

test('it sends logs to sorane channel', function (): void {
    Log::channel('sorane')->error('Test error message', [
        'context' => 'test',
    ]);

    Bus::assertDispatched(SendLogToSoraneJob::class, function ($job): bool {
        return $job->logData['level'] === 'error'
            && $job->logData['message'] === 'Test error message'
            && $job->logData['context']['context'] === 'test';
    });
});

test('it includes environment information', function (): void {
    Log::channel('sorane')->error('Test');

    Bus::assertDispatched(SendLogToSoraneJob::class, function ($job): bool {
        return isset($job->logData['extra']['environment'])
            && isset($job->logData['extra']['laravel_version'])
            && isset($job->logData['extra']['php_version']);
    });
});

test('it respects enabled configuration', function (): void {
    config(['sorane.logging.enabled' => false]);

    Log::channel('sorane')->error('Test');

    Bus::assertNotDispatched(SendLogToSoraneJob::class);
});

test('it respects excluded channels', function (): void {
    config(['sorane.logging.excluded_channels' => ['test-channel']]);

    // Create a custom logger for testing
    $logger = Log::build([
        'driver' => 'sorane',
        'channel' => 'test-channel',
    ]);

    $logger->error('Test');

    Bus::assertNotDispatched(SendLogToSoraneJob::class);
});

test('it handles different log levels', function (): void {
    $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    foreach ($levels as $level) {
        Log::channel('sorane')->{$level}("Test {$level} message");
    }

    Bus::assertDispatchedTimes(SendLogToSoraneJob::class, count($levels));
});

test('it sanitizes context with closures', function (): void {
    Log::channel('sorane')->error('Test', [
        'closure' => fn () => 'test',
        'safe' => 'value',
    ]);

    Bus::assertDispatched(SendLogToSoraneJob::class, function ($job): bool {
        return $job->logData['context']['closure'] === '[Closure]'
            && $job->logData['context']['safe'] === 'value';
    });
});

test('it formats timestamp correctly', function (): void {
    Log::channel('sorane')->error('Test');

    Bus::assertDispatched(SendLogToSoraneJob::class, function ($job): bool {
        // ISO 8601 format
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $job->logData['timestamp']) === 1;
    });
});

test('it includes channel name', function (): void {
    Log::channel('sorane')->error('Test');

    Bus::assertDispatched(SendLogToSoraneJob::class, function ($job): bool {
        return $job->logData['channel'] === 'sorane';
    });
});
