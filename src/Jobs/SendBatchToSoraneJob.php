<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Sorane\Laravel\Services\SoraneApiClient;
use Sorane\Laravel\Services\SoraneBatchBuffer;
use Throwable;

class SendBatchToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public string $type,
        public ?int $maxItems = null
    ) {
        $queueName = config('sorane.batch.queue_name', 'default');
        $this->onQueue($queueName);
    }

    public function handle(SoraneApiClient $client, SoraneBatchBuffer $buffer): void
    {
        $maxItems = $this->maxItems ?? $this->getMaxBatchSize();

        // Get items from buffer
        $items = $buffer->getItems($this->type, $maxItems);

        if (empty($items)) {
            return;
        }

        try {
            // Extract just the data payloads for the API
            $payloads = array_map(fn ($item) => $item['data'], $items);
            $itemIds = array_map(fn ($item) => $item['id'], $items);

            // Send batch to Sorane API
            $result = match ($this->type) {
                'errors' => $client->sendErrorBatch($payloads),
                'events' => $client->sendEventBatch($payloads),
                'logs' => $client->sendLogBatch($payloads),
                'page_visits' => $client->sendPageVisitBatch($payloads),
                'javascript_errors' => $client->sendJavaScriptErrorBatch($payloads),
                default => throw new InvalidArgumentException("Unknown batch type: {$this->type}"),
            };

            // Handle response
            if ($result['success'] ?? false) {
                // Clear successfully sent items
                $buffer->clearItems($this->type, $itemIds);

                Log::info('Sorane batch sent successfully', [
                    'type' => $this->type,
                    'count' => count($items),
                    'processed' => $result['processed'] ?? count($items),
                ]);
            } else {
                // Log error but don't throw - let retry logic handle it
                Log::warning('Sorane batch API returned failure', [
                    'type' => $this->type,
                    'count' => count($items),
                    'message' => $result['message'] ?? 'Unknown error',
                ]);

                // Re-throw to trigger retry
                throw new RuntimeException($result['message'] ?? 'Batch API returned failure');
            }

            // Handle partial failures if API reports them
            if (! empty($result['failed']) && config('sorane.batch.retry_failed_items_individually', true)) {
                $this->retryFailedItemsIndividually($result['failed'], $items);
            }
        } catch (Throwable $e) {
            Log::error('Failed to send batch to Sorane', [
                'type' => $this->type,
                'count' => count($items),
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // If we've exhausted retries, optionally retry items individually
            if ($this->attempts() >= $this->tries && config('sorane.batch.retry_failed_items_individually', true)) {
                $this->retryAllItemsIndividually($items);
                // Clear items from buffer since we're retrying them individually
                $buffer->clearItems($this->type, array_map(fn ($item) => $item['id'], $items));
            }

            throw $e;
        }
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
     * Retry specific failed items individually.
     *
     * @param  array<int, mixed>  $failedIndices
     * @param  array<int, array{id: string, data: array, timestamp: int}>  $items
     */
    protected function retryFailedItemsIndividually(array $failedIndices, array $items): void
    {
        foreach ($failedIndices as $index) {
            if (! isset($items[$index])) {
                continue;
            }

            $this->dispatchIndividualJob($items[$index]['data']);
        }
    }

    /**
     * Retry all items in the batch individually.
     *
     * @param  array<int, array{id: string, data: array, timestamp: int}>  $items
     */
    protected function retryAllItemsIndividually(array $items): void
    {
        Log::warning('Retrying batch items individually', [
            'type' => $this->type,
            'count' => count($items),
        ]);

        foreach ($items as $item) {
            $this->dispatchIndividualJob($item['data']);
        }
    }

    /**
     * Dispatch an individual job for a single item.
     */
    protected function dispatchIndividualJob(array $data): void
    {
        $jobClass = match ($this->type) {
            'errors' => SendErrorToSoraneJob::class,
            'events' => SendEventToSoraneJob::class,
            'logs' => SendLogToSoraneJob::class,
            'page_visits' => SendPageVisitToSoraneJob::class,
            'javascript_errors' => SendJavaScriptErrorToSoraneJob::class,
            default => null,
        };

        if ($jobClass) {
            // Dispatch with flag to skip batching (send directly)
            $jobClass::dispatch($data)->onQueue(config('sorane.batch.queue_name', 'default'));
        }
    }

    /**
     * Get the maximum batch size for this type.
     */
    protected function getMaxBatchSize(): int
    {
        // Map type to config path
        $configPath = match ($this->type) {
            'errors' => 'sorane.errors.batch.size',
            'events' => 'sorane.events.batch.size',
            'logs' => 'sorane.logging.batch.size',
            'page_visits' => 'sorane.website_analytics.batch.size',
            'javascript_errors' => 'sorane.javascript_errors.batch.size',
            default => null,
        };

        return $configPath ? config($configPath, 100) : 100;
    }
}
