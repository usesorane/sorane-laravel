<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Jobs\BaseRanetraceJob;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * Every capture path validates its own input, but a missing rule on any one of
 * them must not be able to buffer an item so large it draws a 413 and takes the
 * whole batch (up to 1000 items) down with it. The budget is therefore enforced
 * in the job layer too, not only by each caller's validation.
 */
beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

function bufferedLogItem(RanetraceBatchBuffer $buffer): array
{
    return Cache::store('array')->get('ranetrace:buffer:logs')[0]['data'];
}

test('an item within the budget is buffered untouched', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleLogJob(['level' => 'error', 'message' => 'boom']))->handle($buffer);

    expect(bufferedLogItem($buffer))
        ->toMatchArray(['level' => 'error', 'message' => 'boom']);
});

test('an oversize free-form field is cut so the item stays within the budget', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleLogJob([
        'level' => 'error',
        'message' => str_repeat('a', 200_000),
    ]))->handle($buffer);

    $item = bufferedLogItem($buffer);

    expect(mb_strlen((string) json_encode($item), '8bit'))
        ->toBeLessThanOrEqual(BaseRanetraceJob::MAX_ITEM_BYTES)
        ->and($item['message'])->toEndWith('... (truncated)')
        ->and($item['level'])->toBe('error');
});

test('an oversize sub-array is replaced wholesale rather than cut mid-structure', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleLogJob([
        'level' => 'error',
        'message' => 'boom',
        'context' => ['blob' => str_repeat('b', 200_000)],
    ]))->handle($buffer);

    $item = bufferedLogItem($buffer);

    expect(mb_strlen((string) json_encode($item), '8bit'))
        ->toBeLessThanOrEqual(BaseRanetraceJob::MAX_ITEM_BYTES)
        ->and($item['context'])->toHaveKey('_truncated');
});
