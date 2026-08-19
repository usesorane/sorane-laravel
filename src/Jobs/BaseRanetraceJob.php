<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Laravel\Utilities\PayloadSizer;
use Throwable;

/**
 * Base class for all Ranetrace jobs providing common functionality.
 */
abstract class BaseRanetraceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Per-item byte budget (JSON-encoded). The batch design assumes an item of
     * roughly this size; a single item above it is sent anyway, because the
     * batch trimmer always keeps at least one, and draws a 413 that discards
     * the whole batch and pauses the type for 15 minutes. Each capture path
     * validates its own input, but a missing rule on any one of them should not
     * be able to poison a batch of up to a thousand items, so the budget is
     * enforced here as well.
     */
    public const int MAX_ITEM_BYTES = 71_680; // 70 KB

    /**
     * Seconds to wait before re-attempting a capture job that could not acquire
     * the buffer lock, giving the contended lock time to clear.
     */
    protected const int BUFFER_RETRY_DELAY = 5;

    /**
     * Per-field budget applied when an item is over {@see MAX_ITEM_BYTES}. Free-
     * form strings are where the bulk always is, so shrinking them keeps the
     * item's structure (and its identifying fields) intact.
     */
    protected const int MAX_ITEM_FIELD_BYTES = 8_192; // 8 KB

    /**
     * Total attempts for a capture job. Lets bufferOrRelease() re-queue an item
     * a couple of times when the buffer lock is briefly contended, instead of
     * dropping it on the first miss. Bounded so a permanently stuck lock cannot
     * loop a job forever.
     */
    public int $tries = 3;

    /**
     * Get the config path for this job type.
     */
    abstract protected function getConfigPath(): string;

    /**
     * Get the allowed keys for payload filtering.
     *
     * @return array<int, string>
     */
    abstract protected function getAllowedKeys(): array;

    /**
     * Handle job failure after all retries exhausted.
     * Logs to 'ranetrace_internal' channel to prevent infinite error loops (never logs to Ranetrace).
     */
    public function failed(Throwable $exception): void
    {
        InternalLogger::critical('Ranetrace job failed after all retries', [
            'job_class' => static::class,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * Buffer a captured item, re-queuing this job if the buffer lock could not
     * be acquired within its wait window.
     *
     * The buffer already blocks briefly for the lock, so a miss here is rare
     * (effectively only a stuck/crashed holder). Re-queuing rather than dropping
     * keeps a captured item from being lost to transient contention. The attempt
     * cap ($tries) bounds the retries so a permanently stuck lock cannot loop the
     * job forever — at which point the item is dropped, matching the package's
     * "lose data before crashing the host" contract. release() never throws into
     * the host (and is a no-op for inline/sync dispatch).
     *
     * A null payload is an item {@see capItemBytes()} already dropped for being
     * irreducibly over budget; there is nothing left to buffer or retry.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected function bufferOrRelease(RanetraceBatchBuffer $buffer, string $type, ?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        if ($buffer->addItem($type, $payload)) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release(self::BUFFER_RETRY_DELAY);
        }
    }

    /**
     * Filter payload to only include allowed keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null Null when the item was over budget and dropped, see {@see capItemBytes()}.
     */
    protected function filterPayload(array $data): ?array
    {
        return $this->capItemBytes(
            collect($data)
                ->only($this->getAllowedKeys())
                ->toArray()
        );
    }

    /**
     * Hold a captured item to the per-item byte budget.
     *
     * Values arriving here have already been scrubbed, so shrinking them cannot
     * expose a secret past a redaction. Oversize free-form strings are cut
     * first, then oversize sub-arrays replaced wholesale (truncating a
     * structure mid-way yields invalid JSON). An item that is somehow still
     * over budget after both is dropped outright: losing one item beats losing
     * the batch it would have poisoned.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null Null when the item is irreducibly over budget and must not be buffered.
     */
    protected function capItemBytes(array $payload): ?array
    {
        if (self::encodedBytes($payload) <= self::MAX_ITEM_BYTES) {
            return $payload;
        }

        foreach ($payload as $key => $value) {
            if (is_string($value) && mb_strlen($value, '8bit') > self::MAX_ITEM_FIELD_BYTES) {
                $payload[$key] = mb_strcut($value, 0, self::MAX_ITEM_FIELD_BYTES).'... (truncated)';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = PayloadSizer::capBytes(
                    $value,
                    self::MAX_ITEM_FIELD_BYTES,
                    'Field exceeded the per-item budget and was removed'
                );
            }
        }

        // Still over budget. The item is dropped rather than replaced with a
        // marker payload: the wire shape is an allow-list per type, so a marker
        // key belongs to no type and the backend's strict field matching would
        // 422 the item — discarding the whole batch of up to 1000 items and
        // pausing the type, which is precisely the failure this budget exists
        // to prevent. Dropping loses one item and nothing else, and the internal
        // log keeps that loss visible.
        if (self::encodedBytes($payload) > self::MAX_ITEM_BYTES) {
            InternalLogger::warning('Captured item exceeded the per-item byte budget and was dropped', [
                'type' => static::class,
                'max_bytes' => self::MAX_ITEM_BYTES,
            ]);

            return null;
        }

        InternalLogger::warning('Captured item exceeded the per-item byte budget and was shrunk', [
            'type' => static::class,
            'max_bytes' => self::MAX_ITEM_BYTES,
        ]);

        return $payload;
    }

    /**
     * Assign the job to the configured queue.
     */
    protected function assignQueue(): void
    {
        $queueName = config($this->getConfigPath().'.queue_name', 'default');
        $this->onQueue($queueName);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function encodedBytes(array $payload): int
    {
        return mb_strlen((string) json_encode($payload), '8bit');
    }
}
