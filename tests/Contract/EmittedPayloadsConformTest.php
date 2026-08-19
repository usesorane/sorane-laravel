<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Tests\Contract\ContractFields;
use Ranetrace\Laravel\Tests\TestCase;
use Ranetrace\Php\Contract\WireContract;

/**
 * The allow-lists say what a payload *may* carry; this file says what the
 * capture paths actually put on the wire. The two can disagree: a builder can
 * omit a field the endpoint requires, or spell one differently from the key the
 * allow-list keeps, and either way the item is rejected at ingest rather than
 * here. So every payload below is produced by the real capture path, buffered
 * through the real job, and read back out of the real buffer.
 */
beforeEach(function (): void {
    config([
        'ranetrace.batch.cache_driver' => 'array',
        // Capture synchronously so the job runs and the buffer holds the item
        // by the time the assertion reads it, without a queue in between.
        'ranetrace.errors.queue' => false,
        'ranetrace.events.queue' => false,
        'ranetrace.logging.queue' => false,
        'ranetrace.javascript_errors.queue' => false,
        'logging.channels.ranetrace' => ['driver' => 'ranetrace', 'level' => 'debug'],
    ]);

    Cache::store('array')->flush();
});

/**
 * One buffered payload per item type, each from its own capture path.
 *
 * The buffer hands items out destructively, so this runs the four captures and
 * drains once. Every test that needs a payload calls it fresh rather than
 * sharing state across tests.
 *
 * @return array<string, array<string, mixed>>
 */
function contractEmittedPayloads(TestCase $test): array
{
    Cache::store('array')->flush();

    (new Ranetrace)->report(new RuntimeException('Something broke'));

    (new Ranetrace)->trackEvent('checkout_completed', ['order_id' => 'ORD-4821', 'total_amount' => 149.95]);

    Log::channel('ranetrace')->warning('Payment gateway timed out', ['order_id' => 'ORD-4821']);

    $test->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => "Cannot read properties of undefined (reading 'total')",
        'url' => 'https://shop.example.com/cart',
        'timestamp' => '2026-08-19T09:30:45+00:00',
        'stack' => "TypeError\n    at renderCart (https://shop.example.com/js/cart.js:214:19)",
        'type' => 'TypeError',
        'filename' => 'https://shop.example.com/js/cart.js',
        'line' => 214,
        'column' => 19,
        'breadcrumbs' => [
            ['timestamp' => '2026-08-19T09:30:41+00:00', 'category' => 'navigation', 'message' => 'Navigated to /cart'],
        ],
        'context' => ['cart_items' => 3],
        'browser_info' => ['screen_width' => 2560, 'connection_type' => '4g'],
    ])->assertOk();

    $buffer = new RanetraceBatchBuffer;
    $payloads = [];

    foreach (WireContract::itemTypes() as $type) {
        $payloads[$type] = $buffer->getItems($type, 1)[0]['data'] ?? [];
    }

    return $payloads;
}

/**
 * Every place a key appears anywhere in a nested payload.
 *
 * @param  array<array-key, mixed>  $payload
 * @return list<string>
 */
function contractKeyPathsFor(array $payload, string $needle, string $path = ''): array
{
    $found = [];

    foreach ($payload as $key => $value) {
        $here = $path === '' ? (string) $key : $path.'.'.$key;

        if ((string) $key === $needle) {
            $found[] = $here;
        }

        if (is_array($value)) {
            $found = array_merge($found, contractKeyPathsFor($value, $needle, $here));
        }
    }

    return $found;
}

test('every emitted key is a field the endpoint declares', function (string $type): void {
    $payload = contractEmittedPayloads($this)[$type];

    $declared = ContractFields::topLevelKeys(WireContract::item($type)['fields']);

    expect($payload)->not->toBe([])
        ->and(array_diff(array_keys($payload), $declared))->toBe([]);
})->with(WireContract::itemTypes());

test('every field the endpoint requires is emitted and not null', function (string $type): void {
    $payload = contractEmittedPayloads($this)[$type];
    $missing = [];

    foreach (ContractFields::requiredTopLevelKeys(WireContract::item($type)['fields']) as $field) {
        if (! array_key_exists($field, $payload) || $payload[$field] === null) {
            $missing[] = $field;
        }
    }

    expect($missing)->toBe([]);
})->with(WireContract::itemTypes());

/**
 * The legacy spelling is tolerated at ingest only so already-deployed Laravel
 * SDK versions keep reporting. This SDK emitting it anywhere, at the top level
 * or nested inside a log's free-shape `extra`, is what keeps that compatibility
 * branch on the backend alive, so the check walks the whole payload rather than
 * only its top level.
 */
test('no emitted payload carries the legacy laravel_version key at any depth', function (): void {
    $found = [];

    foreach (contractEmittedPayloads($this) as $type => $payload) {
        foreach (contractKeyPathsFor($payload, 'laravel_version') as $path) {
            $found[] = $type.': '.$path;
        }
    }

    expect($found)->toBe([]);
});

/**
 * `extra` is free-shape at ingest, so the server enforces nothing here: what
 * the SDKs put inside it is a convention between them, recorded as the log
 * item's `extra_vocabulary`. This pins the Laravel half of that convention,
 * including the move from `laravel_version` to the generic pair the PHP SDK
 * already sends.
 */
test('the log payload attaches the extra vocabulary the contract describes', function (): void {
    $extra = contractEmittedPayloads($this)['logs']['extra'];
    /** @var array<string, mixed> $vocabulary */
    $vocabulary = WireContract::item('logs')['extra_vocabulary']['keys'];

    expect(array_keys($vocabulary))->toEqualCanonicalizing(['environment', 'php_version', 'framework', 'framework_version'])
        ->and($extra['environment'])->toBe(config('app.env'))
        ->and($extra['php_version'])->toBe(phpversion())
        ->and($extra['framework'])->toBe('laravel')
        ->and($extra['framework_version'])->toBe(app()->version());
});
