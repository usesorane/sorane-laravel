<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Tests\Contract\ContractFields;
use Ranetrace\Php\Contract\WireContract;

/**
 * The capture jobs' allow-lists are the last gate before an item is buffered:
 * `filterPayload()` keeps only the keys `getAllowedKeys()` names, so a key the
 * endpoint does not declare cannot reach the wire, and a key the endpoint
 * requires cannot survive a typo here. The errors endpoint allow-lists its
 * field set server-side, where one unexpected key 422s the item and takes the
 * whole batch (up to a thousand items) with it, so a drift between these lists
 * and `contract/items/*.json` is a batch-losing bug rather than a style issue.
 *
 * The fixtures come from the sibling core package (`ranetrace/ranetrace-php`),
 * which both SDKs and the backend read, so this suite compares against one
 * description of the wire rather than a second transcription of it.
 */
beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

/**
 * The buffer type string a capture job writes under, discovered by running it
 * rather than transcribed from the class.
 *
 * The type is what ties a job to a contract item type, and it is a bare string
 * argument inside `handle()`, so reading it back out of the buffer is the only
 * way to learn it that a rename cannot silently invalidate.
 */
function contractCaptureJobBufferType(string $jobClass): string
{
    Cache::store('array')->flush();

    $buffer = new RanetraceBatchBuffer;

    (new $jobClass([]))->handle($buffer);

    foreach (RanetraceBatchBuffer::TYPES as $type) {
        if ($buffer->getItems($type, 1) !== []) {
            return $type;
        }
    }

    throw new RuntimeException("{$jobClass} buffered nothing, so its type could not be discovered.");
}

/**
 * @return array<int, string>
 */
function contractCaptureJobAllowedKeys(string $jobClass): array
{
    return (new ReflectionMethod($jobClass, 'getAllowedKeys'))->invoke(new $jobClass([]));
}

/**
 * @return array<int, class-string>
 */
function contractCaptureJobs(): array
{
    return [HandleErrorJob::class, HandleEventJob::class, HandleLogJob::class, HandleJavaScriptErrorJob::class];
}

test('the capture jobs cover exactly the item types the contract names', function (): void {
    $types = array_map(contractCaptureJobBufferType(...), contractCaptureJobs());

    expect($types)->toEqualCanonicalizing(WireContract::itemTypes());
});

test('a capture job allow-lists exactly the fields its endpoint declares', function (string $jobClass): void {
    $type = contractCaptureJobBufferType($jobClass);

    expect(contractCaptureJobAllowedKeys($jobClass))
        ->toEqualCanonicalizing(ContractFields::topLevelKeys(WireContract::item($type)['fields']));
})->with(contractCaptureJobs());

/**
 * The generic `framework` / `framework_version` pair replaced it. The endpoint
 * still tolerates the old spelling, but only for Laravel SDK versions already
 * deployed: keeping it on the allow-list here would keep that compatibility
 * branch alive on the backend forever.
 */
test('the error allow-list has retired the legacy laravel_version key', function (): void {
    $allowed = contractCaptureJobAllowedKeys(HandleErrorJob::class);
    $legacy = array_keys(WireContract::item('errors')['legacy_fields']);

    expect($allowed)
        ->not->toContain(...$legacy)
        ->toContain('framework', 'framework_version');
});
