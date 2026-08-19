<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Ranetrace\Laravel\Jobs\BaseRanetraceJob;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
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

/**
 * An error payload whose every allowed key holds an oversize free-form string:
 * each one is shrunk to the per-field budget, yet nineteen of them together stay
 * far above the per-item budget — the only way an item reaches the drop branch.
 *
 * @return array<string, string>
 */
function irreduciblyOversizeErrorData(): array
{
    $keys = [
        'message', 'file', 'line', 'type', 'environment', 'trace', 'headers',
        'context', 'highlight_line', 'user', 'timestamp', 'url', 'method',
        'php_version', 'framework', 'framework_version', 'is_console',
        'console_command', 'console_arguments',
    ];

    return array_fill_keys($keys, str_repeat('a', 20_000));
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

test('an item still over the budget after shrinking is dropped, not buffered', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleErrorJob(irreduciblyOversizeErrorData()))->handle($buffer);

    expect(Cache::store('array')->get('ranetrace:buffer:errors'))->toBeNull();
});

test('a dropped item never leaves a _truncated marker in the buffered output', function (): void {
    // `_truncated` is on no job's allow-list, so a marker item would fail the
    // backend's strict field matching and 422 the whole batch it travels in.
    $buffer = new RanetraceBatchBuffer;

    (new HandleErrorJob(irreduciblyOversizeErrorData()))->handle($buffer);
    (new HandleLogJob(['level' => 'error', 'message' => 'boom']))->handle($buffer);

    $buffered = array_merge(
        Cache::store('array')->get('ranetrace:buffer:errors', []),
        Cache::store('array')->get('ranetrace:buffer:logs', [])
    );

    foreach ($buffered as $item) {
        expect($item['data'])->not->toHaveKey('_truncated');
    }

    expect($buffered)->toHaveCount(1);
});

test('the drop is recorded on the internal channel, distinctly from a shrink', function (): void {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('ranetrace_internal')->andReturn($logger);

    (new HandleErrorJob(irreduciblyOversizeErrorData()))->handle(new RanetraceBatchBuffer);

    $logger->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'dropped')
            && ($context['type'] ?? null) === HandleErrorJob::class
    );
});
