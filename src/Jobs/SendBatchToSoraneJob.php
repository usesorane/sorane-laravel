<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Sorane\Laravel\Services\SoraneApiClient;
use Sorane\Laravel\Services\SoraneBatchBuffer;
use Sorane\Laravel\Services\SoranePauseManager;
use Throwable;

class SendBatchToSoraneJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, array{id: string, data: array, timestamp: int}> */
    protected array $items = [];

    public function __construct(
        public string $type,
        public ?int $maxItems = null
    ) {
        $queueName = config('sorane.batch.queue_name', 'default');
        $this->onQueue($queueName);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "sorane:batch:{$this->type}";
    }

    public function handle(SoraneApiClient $client, SoraneBatchBuffer $buffer, SoranePauseManager $pauseManager): void
    {
        $maxItems = $this->maxItems ?? $this->getMaxBatchSize();

        // Get items from buffer (atomically removes them)
        $this->items = $buffer->getItems($this->type, $maxItems);

        if (empty($this->items)) {
            return;
        }

        try {
            // Extract just the data payloads for the API
            $payloads = array_map(fn ($item) => $item['data'], $this->items);

            // Send batch to Sorane API
            $result = match ($this->type) {
                'errors' => $client->sendErrorBatch($payloads),
                'events' => $client->sendEventBatch($payloads),
                'logs' => $client->sendLogBatch($payloads),
                'page_visits' => $client->sendPageVisitBatch($payloads),
                'javascript_errors' => $client->sendJavaScriptErrorBatch($payloads),
                default => throw new InvalidArgumentException("Unknown batch type: {$this->type}"),
            };

            // Handle response based on status code
            $this->handleResponse($result, $buffer, $pauseManager);
        } catch (Throwable $e) {
            // Re-throw to trigger job retry
            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        $this->logError('Batch job failed after all retries', [
            'type' => $this->type,
            'exception' => $exception->getMessage(),
        ]);

        // Set feature pause for 15 minutes after final retry
        $pauseManager = app(SoranePauseManager::class);
        $pauseManager->setFeaturePause($this->type, 900, '500');
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
     * Handle API response according to spec.
     */
    protected function handleResponse(array $result, SoraneBatchBuffer $buffer, SoranePauseManager $pauseManager): void
    {
        $status = $result['status'] ?? 0;
        $data = $result['data'] ?? [];

        // Network-level errors (status 0)
        if ($status === 0) {
            $this->logError('Network error during batch send', [
                'type' => $this->type,
                'error' => $result['error'] ?? 'Unknown network error',
                'items_count' => count($this->items),
            ]);

            // Re-add all items and rethrow to trigger retry
            $this->reAddAllItemsToBuffer($buffer);

            throw new RuntimeException($result['error'] ?? 'Network error');
        }

        // Handle based on HTTP status code
        match ($status) {
            200 => $this->handle200Response($data, $buffer),
            401 => $this->handle401Response($buffer, $pauseManager, $data),
            403 => $this->handle403Response($buffer, $pauseManager, $data),
            413 => $this->handle413Response($pauseManager, $data),
            422 => $this->handle422Response($pauseManager, $data),
            429 => $this->handle429Response($buffer, $pauseManager, $result['headers'] ?? []),
            500 => $this->handle500Response($buffer),
            default => $this->handleUnknownResponse($status, $buffer),
        };
    }

    /**
     * Handle 200 OK response.
     */
    protected function handle200Response(array $data, SoraneBatchBuffer $buffer): void
    {
        $items = $data['items'] ?? [];
        $received = $items['received'] ?? 0;
        $processed = $items['processed'] ?? 0;
        $ignored = $items['ignored'] ?? 0;
        $failed = $items['failed'] ?? 0;
        $unprocessed = $items['unprocessed'] ?? 0;
        $unprocessedIndexes = $data['unprocessed_indexes'] ?? [];

        // Log non-zero failed counts
        if ($failed > 0) {
            $this->logWarning('Some items failed during processing', [
                'type' => $this->type,
                'received' => $received,
                'processed' => $processed,
                'ignored' => $ignored,
                'failed' => $failed,
            ]);
        }

        // Log unprocessed items (timeout scenario)
        if ($unprocessed > 0) {
            $this->logInfo('Some items were not processed due to timeout', [
                'type' => $this->type,
                'received' => $received,
                'processed' => $processed,
                'unprocessed' => $unprocessed,
            ]);

            // Re-add only unprocessed items to buffer
            $this->reAddUnprocessedItemsToBuffer($buffer, $unprocessedIndexes);
        }
    }

    /**
     * Handle 401 Unauthorized response.
     */
    protected function handle401Response(SoraneBatchBuffer $buffer, SoranePauseManager $pauseManager, array $data): void
    {
        $this->logError('API authentication failed - invalid or revoked API key', [
            'type' => $this->type,
            'message' => $data['error']['message'] ?? 'Unauthorized',
        ]);

        // Set global pause for 15 minutes
        $pauseManager->setGlobalPause(900, '401');

        // Re-add all items to buffer
        $this->reAddAllItemsToBuffer($buffer);
    }

    /**
     * Handle 403 Forbidden response.
     */
    protected function handle403Response(SoraneBatchBuffer $buffer, SoranePauseManager $pauseManager, array $data): void
    {
        $this->logError('API request forbidden', [
            'type' => $this->type,
            'message' => $data['error']['message'] ?? 'Forbidden',
        ]);

        // Set feature pause for 15 minutes
        $pauseManager->setFeaturePause($this->type, 900, '403');

        // Re-add all items to buffer
        $this->reAddAllItemsToBuffer($buffer);
    }

    /**
     * Handle 413 Payload Too Large response.
     */
    protected function handle413Response(SoranePauseManager $pauseManager, array $data): void
    {
        $this->logCritical('Payload too large - indicates client bug', [
            'type' => $this->type,
            'items_count' => count($this->items),
            'message' => $data['error']['message'] ?? 'Payload Too Large',
        ]);

        // Set feature pause for 15 minutes
        $pauseManager->setFeaturePause($this->type, 900, '413');

        // Do NOT re-add items (they're too large)
    }

    /**
     * Handle 422 Unprocessable Entity response.
     */
    protected function handle422Response(SoranePauseManager $pauseManager, array $data): void
    {
        $this->logError('Validation failed - indicates schema drift or malformed items', [
            'type' => $this->type,
            'items_count' => count($this->items),
            'message' => $data['error']['message'] ?? 'Unprocessable Entity',
        ]);

        // Set feature pause for 15 minutes
        $pauseManager->setFeaturePause($this->type, 900, '422');

        // Do NOT re-add items (they're invalid)
    }

    /**
     * Handle 429 Too Many Requests response.
     */
    protected function handle429Response(SoraneBatchBuffer $buffer, SoranePauseManager $pauseManager, array $headers): void
    {
        $retryAfter = (int) ($headers['retry-after'] ?? 60);

        $this->logWarning('Rate limit exceeded', [
            'type' => $this->type,
            'retry_after' => $retryAfter,
        ]);

        // Set feature pause based on Retry-After header
        $pauseManager->setFeaturePause($this->type, $retryAfter, '429');

        // Re-add all items to buffer
        $this->reAddAllItemsToBuffer($buffer);
    }

    /**
     * Handle 500 Internal Server Error response.
     */
    protected function handle500Response(SoraneBatchBuffer $buffer): void
    {
        $this->logError('Server error during batch processing', [
            'type' => $this->type,
            'items_count' => count($this->items),
            'attempt' => $this->attempts(),
        ]);

        // Re-add all items and rethrow to trigger retry
        $this->reAddAllItemsToBuffer($buffer);

        throw new RuntimeException('Server returned 500 error');
    }

    /**
     * Handle unknown status code response.
     */
    protected function handleUnknownResponse(int $status, SoraneBatchBuffer $buffer): void
    {
        $this->logError('Unexpected API response status', [
            'type' => $this->type,
            'status' => $status,
            'items_count' => count($this->items),
        ]);

        // Re-add all items and rethrow to trigger retry
        $this->reAddAllItemsToBuffer($buffer);

        throw new RuntimeException("Unexpected status code: {$status}");
    }

    /**
     * Re-add all items to the buffer.
     */
    protected function reAddAllItemsToBuffer(SoraneBatchBuffer $buffer): void
    {
        foreach ($this->items as $item) {
            $buffer->addItem($this->type, $item['data']);
        }
    }

    /**
     * Re-add only unprocessed items to the buffer using their indexes.
     *
     * @param  array<int, int>  $indexes
     */
    protected function reAddUnprocessedItemsToBuffer(SoraneBatchBuffer $buffer, array $indexes): void
    {
        foreach ($indexes as $index) {
            if (isset($this->items[$index])) {
                $buffer->addItem($this->type, $this->items[$index]['data']);
            }
        }
    }

    /**
     * Get the maximum batch size for this type.
     */
    protected function getMaxBatchSize(): int
    {
        return 1000; // Per API spec
    }

    /**
     * Log to sorane_internal channel at error level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logError(string $message, array $context = []): void
    {
        try {
            Log::channel('sorane_internal')->error($message, $context);
        } catch (Throwable) {
            // Silent failure if channel not configured
        }
    }

    /**
     * Log to sorane_internal channel at warning level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        try {
            Log::channel('sorane_internal')->warning($message, $context);
        } catch (Throwable) {
            // Silent failure if channel not configured
        }
    }

    /**
     * Log to sorane_internal channel at info level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logInfo(string $message, array $context = []): void
    {
        try {
            Log::channel('sorane_internal')->info($message, $context);
        } catch (Throwable) {
            // Silent failure if channel not configured
        }
    }

    /**
     * Log to sorane_internal channel at critical level.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logCritical(string $message, array $context = []): void
    {
        try {
            Log::channel('sorane_internal')->critical($message, $context);
        } catch (Throwable) {
            // Silent failure if channel not configured
        }
    }
}
