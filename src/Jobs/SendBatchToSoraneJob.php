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
use Throwable;

class SendBatchToSoraneJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 60;

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

    public function handle(SoraneApiClient $client, SoraneBatchBuffer $buffer): void
    {
        $maxItems = $this->maxItems ?? $this->getMaxBatchSize();

        // Get items from buffer (atomically removes them)
        $items = $buffer->getItems($this->type, $maxItems);

        if (empty($items)) {
            return;
        }

        try {
            // Extract just the data payloads for the API
            $payloads = array_map(fn ($item) => $item['data'], $items);

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
                Log::info('Sorane batch sent successfully', [
                    'type' => $this->type,
                    'count' => count($items),
                    'processed' => $result['processed'] ?? count($items),
                ]);
            } else {
                // Log error and re-add items to buffer for retry
                Log::warning('Sorane batch API returned failure', [
                    'type' => $this->type,
                    'count' => count($items),
                    'message' => $result['message'] ?? 'Unknown error',
                ]);

                // Re-add items to buffer for retry
                $this->reAddItemsToBuffer($buffer, $items);

                // Re-throw to trigger retry
                throw new RuntimeException($result['message'] ?? 'Batch API returned failure');
            }
        } catch (RuntimeException $e) {
            // RuntimeException from failed API response - items already re-added above
            throw $e;
        } catch (Throwable $e) {
            // Unexpected error (network, timeout, etc) - re-add items
            Log::error('Failed to send batch to Sorane', [
                'type' => $this->type,
                'count' => count($items),
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Re-add items to buffer for batch retry (items are never sent individually)
            $this->reAddItemsToBuffer($buffer, $items);

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
     * Re-add items to the buffer for retry.
     *
     * @param  array<int, array{id: string, data: array, timestamp: int}>  $items
     */
    protected function reAddItemsToBuffer(SoraneBatchBuffer $buffer, array $items): void
    {
        foreach ($items as $item) {
            $buffer->addItem($this->type, $item['data']);
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
