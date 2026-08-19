<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Php\Http\BatchOutcome;
use Ranetrace\Php\Http\PauseScope;
use Ranetrace\Php\Http\ResponsePolicy;
use Throwable;

class SendBatchToRanetraceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cache key prefix for the per-type "last successful batch" timestamp,
     * read by ranetrace:status to detect a stalled/unscheduled work command.
     */
    public const string LAST_BATCH_PREFIX = 'ranetrace:last_batch:';

    /**
     * Soft byte budget for one batch request. The API hard-limits requests to
     * 5MB; we trim to ~4.5MB and re-buffer the rest, leaving headroom for the
     * JSON envelope so an oversize 413 (whole-batch discard + 15-min pause) is
     * impossible. Single items are separately bounded by the per-field caps in
     * Ranetrace / RanetraceLogHandler.
     */
    protected const int MAX_BATCH_BYTES = 4_500_000;

    /**
     * Total attempts: 1 initial + 3 retries. Combined with backoff() of
     * 60/300/900s this produces the 21-minute retry window mandated by
     * client-response-handling.md.
     */
    public int $tries = 4;

    /**
     * Keep the uniqueness lock alive across the full retry envelope (backoff
     * 60+300+900s plus per-attempt runtime) so a concurrent ranetrace:work run
     * cannot dispatch a duplicate batch job for the same type during a retry gap.
     */
    public int $uniqueFor = 1500;

    /** @var array<int, array{id: string, data: array, timestamp: int}> */
    protected array $items = [];

    public function __construct(
        public string $type,
        public ?int $maxItems = null
    ) {
        $queueName = config('ranetrace.batch.queue_name', 'default');
        $this->onQueue($queueName);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "ranetrace:batch:{$this->type}";
    }

    public function handle(RanetraceApiClient $client, RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager): void
    {
        $maxItems = $this->maxItems ?? $this->getMaxBatchSize();

        // Get items from buffer (atomically removes them)
        $this->items = $buffer->getItems($this->type, $maxItems);

        if (empty($this->items)) {
            return;
        }

        // Pre-flight size guard: trim the batch to the byte budget and re-buffer
        // the overflow so an oversize request (413 → whole-batch discard + pause)
        // is impossible. The remainder drains on the next ranetrace:work run.
        $deferred = $this->trimToByteBudget();
        if ($deferred !== []) {
            $buffer->addItems($this->type, array_map(fn (array $item): array => $item['data'], $deferred));
            $this->logInfo('Deferred items to keep the batch under the size limit', [
                'type' => $this->type,
                'sent' => count($this->items),
                'deferred' => count($deferred),
            ]);
        }

        // Extract just the data payloads for the API. Where the batch goes is
        // the shared endpoint table's answer, so an unknown type throws there
        // rather than being addressed to a guess.
        $result = $client->sendBatchOfType(
            $this->type,
            array_map(fn ($item) => $item['data'], $this->items),
        );

        // Handle response based on status code (releases on retryable failures)
        $this->handleResponse($result, $buffer, $pauseManager);
    }

    /**
     * Handle job failure after all retries exhausted.
     *
     * The controlled failure paths (network, 5xx, unexpected status) retry via
     * release() and give up gracefully without throwing, so they never reach
     * here. This only fires for an unexpected exception (e.g. an unknown batch
     * type) — log it internally and pause the feature for 15 minutes.
     */
    public function failed(Throwable $exception): void
    {
        $this->logError('Batch job failed after all retries', [
            'type' => $this->type,
            'exception' => $exception->getMessage(),
        ]);

        $pauseManager = app(RanetracePauseManager::class);
        $pauseManager->setFeaturePause($this->type, ResponsePolicy::PAUSE_SECONDS, 'exception');
    }

    /**
     * Calculate backoff time based on attempt number.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900]; // 1min, 5min, 15min
    }

    /**
     * Act on one API response.
     *
     * What each status MEANS is decided by `Ranetrace\Php\Http\ResponsePolicy`,
     * shared with `ranetrace/ranetrace-php` so the two SDKs cannot drift on the
     * matrix the server relies on. What stays here is this SDK's own half: the
     * cache-backed pause store, the diagnostics wording, and the retry envelope.
     *
     * The envelope is the one place the two designs part company, and the
     * outcome names it: a transient failure has somewhere to be released to
     * here, so the 60/300/900s backoff is spent BEFORE the contracted pause is
     * taken. The file-based worker has no queue and pauses on the spot. Both
     * land on the same pause, which is the part the contract fixes.
     */
    protected function handleResponse(array $result, RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager): void
    {
        $outcome = (new ResponsePolicy)->decide($result);

        $this->logOutcome($result, $outcome);

        if ($outcome->stampLastBatch) {
            // Record a successful drain so ranetrace:status can detect a
            // stalled worker.
            $this->recordLastBatch();
        }

        // Retries are driven by release(), never by throwing, so the transport
        // failure the API client already caught (e.g. a cURL timeout) does not
        // escape into the host application. See retryWithBackoffOrPause().
        if ($outcome->transient) {
            $this->retryWithBackoffOrPause($buffer, $pauseManager, $outcome->reason);

            return;
        }

        if ($outcome->rebuffer) {
            $this->reAddAllItemsToBuffer($buffer);
        }

        if ($outcome->counters?->hasUnprocessed() === true) {
            $buffer->addItems($this->type, $outcome->unprocessedPayloads($this->items));
        }

        $seconds = $outcome->pauseSeconds ?? ResponsePolicy::PAUSE_SECONDS;

        match ($outcome->pauseScope) {
            // Global, not per feature: a rejected key is not a problem with this
            // endpoint.
            PauseScope::Everything => $pauseManager->setGlobalPause($seconds, $outcome->reason),
            PauseScope::Feature => $pauseManager->setFeaturePause($this->type, $seconds, $outcome->reason),
            PauseScope::None => null,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function logOutcome(array $result, BatchOutcome $outcome): void
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        match ($outcome->status) {
            0 => $this->logError('Network error during batch send', [
                'type' => $this->type,
                'error' => $result['error'] ?? 'Unknown network error',
                'items_count' => count($this->items),
            ]),
            200 => $this->logSuccess($outcome),
            401 => $this->logError('API authentication failed - invalid or revoked API key', [
                'type' => $this->type,
                'message' => ResponsePolicy::errorMessage($data, 'Unauthorized'),
            ]),
            403 => $this->logError('API request forbidden', [
                'type' => $this->type,
                'message' => ResponsePolicy::errorMessage($data, 'Forbidden'),
            ]),
            413 => $this->logCritical('Payload too large - indicates client bug', [
                'type' => $this->type,
                'items_count' => count($this->items),
                'message' => ResponsePolicy::errorMessage($data, 'Payload Too Large'),
            ]),
            422 => $this->logError('Validation failed - indicates schema drift or malformed items', [
                'type' => $this->type,
                'items_count' => count($this->items),
                'message' => ResponsePolicy::errorMessage($data, 'Unprocessable Entity'),
            ]),
            429 => $this->logWarning('Rate limit exceeded', [
                'type' => $this->type,
                'retry_after' => $outcome->pauseSeconds,
            ]),
            500 => $this->logError('Server error during batch processing', [
                'type' => $this->type,
                'items_count' => count($this->items),
                'attempt' => $this->attempts(),
            ]),
            default => $this->logError('Unexpected API response status', [
                'type' => $this->type,
                'status' => $outcome->status,
                'items_count' => count($this->items),
            ]),
        };
    }

    /**
     * The per-item tallies a 200 carries back. Failed items are terminal by
     * design: the server rejected them individually and would reject them
     * again, so re-sending would loop forever.
     */
    protected function logSuccess(BatchOutcome $outcome): void
    {
        $counters = $outcome->counters;

        if ($counters === null) {
            return;
        }

        if ($counters->hasFailures()) {
            $this->logWarning('Some items failed during processing', [
                'type' => $this->type,
                'received' => $counters->received,
                'processed' => $counters->processed,
                'ignored' => $counters->ignored,
                'failed' => $counters->failed,
            ]);
        }

        if ($counters->hasUnprocessed()) {
            $this->logInfo('Some items were not processed due to timeout', [
                'type' => $this->type,
                'received' => $counters->received,
                'processed' => $counters->processed,
                'unprocessed' => $counters->unprocessed,
            ]);
        }
    }

    /**
     * Retry a transient send failure (network, 5xx, or an unexpected status)
     * with backoff, or — once the retry envelope is exhausted — give up by
     * pausing the feature for 15 minutes.
     *
     * Retries are driven by release(), NOT by throwing. An exception that
     * escapes a queued job is reported through the HOST application's exception
     * handler — its logs, its failed_jobs table, and any error tracker — and is
     * additionally re-captured by Ranetrace's own reportable() hook, leaking an
     * internal transport failure into the customer's application. release()
     * re-queues the job with the same backoff schedule but surfaces nothing.
     */
    protected function retryWithBackoffOrPause(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager, string $reason): void
    {
        $this->reAddAllItemsToBuffer($buffer);

        $backoff = $this->backoff();

        // attempts() is 1-based. While attempts remain, re-queue for the next
        // one; the delay mirrors the backoff() schedule (60/300/900s).
        if ($this->attempts() < $this->tries) {
            $this->release($backoff[$this->attempts() - 1] ?? (int) end($backoff));

            return;
        }

        // Retry envelope exhausted — pause the feature so the worker stops
        // hammering a degraded endpoint. The items stay buffered and drain on a
        // later run once the pause lifts. Return without throwing.
        $this->logError('Batch send abandoned after exhausting retries', [
            'type' => $this->type,
            'reason' => $reason,
            'attempts' => $this->attempts(),
        ]);

        $pauseManager->setFeaturePause($this->type, ResponsePolicy::PAUSE_SECONDS, $reason);
    }

    /**
     * Re-add all items to the buffer in a single locked operation.
     */
    protected function reAddAllItemsToBuffer(RanetraceBatchBuffer $buffer): void
    {
        $buffer->addItems(
            $this->type,
            array_map(fn (array $item): array => $item['data'], $this->items)
        );
    }

    /**
     * Record the timestamp of a successful batch send for this type, so
     * ranetrace:status can warn when buffers hold items but no recent drain
     * has occurred (a sign ranetrace:work is not scheduled).
     */
    protected function recordLastBatch(): void
    {
        $cacheDriver = config('ranetrace.batch.cache_driver', 'file');

        Cache::store($cacheDriver)->put(
            self::LAST_BATCH_PREFIX.$this->type,
            now()->timestamp,
            now()->addWeek()
        );
    }

    /**
     * Get the maximum batch size for this type.
     */
    protected function getMaxBatchSize(): int
    {
        return 1000; // Per API spec
    }

    /**
     * Trim items off the tail of $this->items so the serialized batch stays
     * within MAX_BATCH_BYTES, returning the removed items for re-buffering.
     * Always keeps at least one item — a single over-budget item can't be split
     * (per-field caps bound single items).
     *
     * @return array<int, array{id: string, data: array, timestamp: int}>
     */
    protected function trimToByteBudget(): array
    {
        $bytes = 0;

        foreach ($this->items as $index => $item) {
            $bytes += mb_strlen((string) json_encode($item['data']), '8bit');

            if ($index > 0 && $bytes > self::MAX_BATCH_BYTES) {
                $deferred = array_slice($this->items, $index);
                $this->items = array_slice($this->items, 0, $index);

                return $deferred;
            }
        }

        return [];
    }

    /**
     * Log to ranetrace_internal channel at error level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logError(string $message, array $context = []): void
    {
        InternalLogger::error($message, $context);
    }

    /**
     * Log to ranetrace_internal channel at warning level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        InternalLogger::warning($message, $context);
    }

    /**
     * Log to ranetrace_internal channel at info level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logInfo(string $message, array $context = []): void
    {
        InternalLogger::info($message, $context);
    }

    /**
     * Log to ranetrace_internal channel at critical level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logCritical(string $message, array $context = []): void
    {
        InternalLogger::critical($message, $context);
    }
}
