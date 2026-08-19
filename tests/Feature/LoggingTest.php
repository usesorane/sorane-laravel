<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\LogRecord;
use Ranetrace\Laravel\Jobs\HandleLogJob;

beforeEach(function (): void {
    Bus::fake();
    config([
        'ranetrace.logging.enabled' => true,
        'ranetrace.logging.queue' => true,
        'ranetrace.logging.queue_name' => 'default',
        'logging.channels.ranetrace' => [
            'driver' => 'ranetrace',
            'level' => 'debug',
        ],
    ]);
});

test('it sends logs to ranetrace channel', function (): void {
    Log::channel('ranetrace')->error('Test error message', [
        'context' => 'test',
    ]);

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        return $job->getLogData()['level'] === 'error'
            && $job->getLogData()['message'] === 'Test error message'
            && $job->getLogData()['context']['context'] === 'test';
    });
});

test('it includes environment information', function (): void {
    Log::channel('ranetrace')->error('Test');

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        return isset($job->getLogData()['extra']['environment'])
            && $job->getLogData()['extra']['framework'] === 'laravel'
            && $job->getLogData()['extra']['framework_version'] === app()->version()
            && isset($job->getLogData()['extra']['php_version'])
            && ! array_key_exists('laravel_version', $job->getLogData()['extra']);
    });
});

test('it preserves environment metadata even when the extra payload is oversized', function (): void {
    $logger = Log::channel('ranetrace');

    // Inject an over-budget (>10KB) extra payload via a Monolog processor.
    $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
        return $record->with(extra: ['huge' => str_repeat('a', 20_000)]);
    });

    $logger->error('Test');

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        $extra = $job->getLogData()['extra'];

        // The oversized user extra is dropped wholesale, but the small,
        // known-safe environment metadata still survives for triage.
        return isset($extra['_truncated'])
            && ! isset($extra['huge'])
            && isset($extra['environment'], $extra['framework'], $extra['framework_version'], $extra['php_version']);
    });
});

test('it respects enabled configuration', function (): void {
    config(['ranetrace.logging.enabled' => false]);

    Log::channel('ranetrace')->error('Test');

    Bus::assertNotDispatched(HandleLogJob::class);
});

test('it respects excluded channels', function (): void {
    config(['ranetrace.logging.excluded_channels' => ['test-channel']]);

    // Create a custom logger for testing
    $logger = Log::build([
        'driver' => 'ranetrace',
        'channel' => 'test-channel',
    ]);

    $logger->error('Test');

    Bus::assertNotDispatched(HandleLogJob::class);
});

test('it handles different log levels', function (): void {
    $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    foreach ($levels as $level) {
        Log::channel('ranetrace')->{$level}("Test {$level} message");
    }

    Bus::assertDispatchedTimes(HandleLogJob::class, count($levels));
});

test('it sanitizes context with closures', function (): void {
    Log::channel('ranetrace')->error('Test', [
        'closure' => fn () => 'test',
        'safe' => 'value',
    ]);

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        return $job->getLogData()['context']['closure'] === '[Closure]'
            && $job->getLogData()['context']['safe'] === 'value';
    });
});

test('it formats timestamp correctly', function (): void {
    Log::channel('ranetrace')->error('Test');

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        // ISO 8601 format
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $job->getLogData()['timestamp']) === 1;
    });
});

test('it includes channel name', function (): void {
    Log::channel('ranetrace')->error('Test');

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        return $job->getLogData()['channel'] === 'ranetrace';
    });
});

test('it redacts secrets in context', function (): void {
    Log::channel('ranetrace')->error('Payment failed', [
        'api_key' => 'sk_live_secret',
        'password' => 'hunter2',
        'order_id' => 42,
    ]);

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        $context = $job->getLogData()['context'];

        return $context['api_key'] === '[REDACTED]'
            && $context['password'] === '[REDACTED]'
            && $context['order_id'] === 42;
    });
});

test('it scrubs secrets inside URL values in context', function (): void {
    // The key `endpoint` is NOT sensitive, so key-based scrubbing leaves it
    // alone; only scrubDeep's URL-value pass redacts the inner api_key param.
    Log::channel('ranetrace')->error('Webhook dispatched', [
        'endpoint' => 'https://api.test/hook?api_key=sk_live_x&page=2',
    ]);

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        $context = $job->getLogData()['context'];

        return $context['endpoint'] === 'https://api.test/hook?api_key=[REDACTED]&page=2';
    });
});

test('it scrubs a sensitive path segment inside a URL value in context', function (): void {
    // A path segment carries no marker saying it holds a token: only the route
    // that named it `{token}` knows. Log context is free-form and holds URLs
    // from requests other than the one being handled, so each is matched
    // against the route table on its own. This is the per-URL resolver the
    // handler hands the shared builder, and the reason the shared scrubber
    // grew a callable seam rather than taking one pre-resolved list.
    Route::get('/reset-password/{token}', fn (): string => 'reset');

    Log::channel('ranetrace')->error('Reset mail sent', [
        'link' => 'http://localhost/reset-password/live-token-abc',
        'other' => 'http://localhost/articles/hello',
    ]);

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        $context = $job->getLogData()['context'];

        return $context['link'] === 'http://localhost/reset-password/[REDACTED]'
            && $context['other'] === 'http://localhost/articles/hello';
    });
});

test('it redacts key=value secrets in the message', function (): void {
    Log::channel('ranetrace')->error('Auth failed for token=sk_live_abc123 retrying');

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        $message = $job->getLogData()['message'];

        return str_contains($message, '[REDACTED]')
            && ! str_contains($message, 'sk_live_abc123');
    });
});

test('it truncates an over-long message', function (): void {
    Log::channel('ranetrace')->error(str_repeat('a', 60_000));

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        $message = $job->getLogData()['message'];

        return mb_strlen($message) <= 50_000
            && str_ends_with($message, '... (truncated)');
    });
});

test('it caps oversized context wholesale rather than shipping it', function (): void {
    Log::channel('ranetrace')->error('Test', ['blob' => str_repeat('a', 60_000)]);

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        $context = $job->getLogData()['context'];

        return isset($context['_truncated']) && ! isset($context['blob']);
    });
});

test('it respects the global ranetrace.enabled flag', function (): void {
    config(['ranetrace.enabled' => false]);

    Log::channel('ranetrace')->error('Test');

    Bus::assertNotDispatched(HandleLogJob::class);
});

test('it still dispatches when the queue is disabled', function (): void {
    config(['ranetrace.logging.queue' => false]);

    Log::channel('ranetrace')->error('Test');

    Bus::assertDispatched(HandleLogJob::class);
});
