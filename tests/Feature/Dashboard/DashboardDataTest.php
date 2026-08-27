<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('collectStatus returns the canonical status structure', function (): void {
    $status = app(DashboardData::class)->collectStatus();

    expect($status)
        ->toHaveKeys(['healthy', 'timestamp', 'pauses', 'buffers', 'drain', 'failed_jobs_last_24h', 'config'])
        ->and($status['healthy'])->toBeTrue()
        ->and($status['pauses'])->toHaveKeys(['global', 'features'])
        ->and($status['buffers'])->toHaveKeys(['total', 'max_per_feature', 'features'])
        ->and($status['buffers']['features'])->toHaveKeys(RanetraceBatchBuffer::TYPES)
        ->and($status['drain'])->toHaveKeys(['last_batch', 'stalled'])
        ->and($status['config'])->toHaveKeys(['enabled', 'api_key_configured', 'cache_driver', 'queue_name'])
        ->and($status['config']['api_key_configured'])->toBeTrue();
});

test('the ingest key is reported as present without exposing its value', function (): void {
    Config::set('ranetrace.key', 'ingest-key');

    $config = app(DashboardData::class)->collectStatus()['config'];

    expect($config['api_key_configured'])->toBeTrue()
        ->and($config)->not->toContain('ingest-key');
});

test('no MCP surface is reported now the local server is gone', function (): void {
    // The MCP server is hosted by Ranetrace and the token lives with the MCP
    // client, so this application registers nothing for it to introspect.
    $labels = collect(app(DashboardData::class)->registeredSurfaces())->pluck('label');

    expect($labels)->not->toContain('MCP server');
});

test('a numeric-string last-batch timestamp (Redis-style) counts as a recent drain', function (): void {
    // Redis and Memcached return bare numbers as numeric strings, not ints. A
    // buffer holding an overdue item must NOT be reported as stalled when such a
    // timestamp shows a recent successful drain — the production false-positive.
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);

    // Age the item past the drain window, then record a *string* drain timestamp
    // for "now" — exactly the shape a Redis store hands back.
    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
    $now = now()->timestamp;
    Cache::store('array')->put(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events', (string) $now, 3600);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['last_batch']['events'])->toBe($now)
        ->and($status['drain']['stalled'])->not->toContain('events');
});

test('the Ranetrace log channel surface reports wired even when logging is disabled', function (): void {
    // The channel is registered unconditionally (so a committed stack that
    // references `ranetrace` stays valid everywhere); the handler short-circuits
    // when disabled. The installation-truth surface must therefore report it as
    // wired regardless of the logging.enabled flag. `note` stays null because
    // `surface()` only surfaces a note when a check is NOT ok.
    $this->configOverrides = ['ranetrace.logging.enabled' => false];
    $this->reloadApplication();

    $surface = collect(app(DashboardData::class)->registeredSurfaces())
        ->firstWhere('label', 'Ranetrace log channel');

    expect($surface)->not->toBeNull()
        ->and($surface['ok'])->toBeTrue()
        ->and($surface['note'])->toBeNull();
});

test('ranetrace:status --json output is unchanged after the DashboardData extraction', function (): void {
    // Populate state across panels (a pause, a buffered item) so parity is
    // checked against a populated structure, not just an empty baseline.
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    // Freeze time so the command's `timestamp` and `time_remaining_seconds`
    // (both derived from now()) are identical to the direct service call below.
    $this->freezeTime();

    Artisan::call('ranetrace:status', ['--json' => true]);
    $commandJson = json_decode(mb_trim(Artisan::output()), true);

    $serviceData = app(DashboardData::class)->collectStatus();

    expect($commandJson)->toEqual($serviceData);
});
