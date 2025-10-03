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
                // Good
            } else {
                // Throw to trigger retry
                throw new RuntimeException($result['message'] ?? 'Batch API returned failure');
            }
        } catch (Throwable $e) {
            // Re-add items to buffer for batch retry (items are never sent individually)
            $this->reAddItemsToBuffer($buffer, $items);

            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     * Logs to single channel (not Sorane) to prevent infinite error loops.
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('single')
            ->critical('Sorane batch job failed after all retries', [
                'type' => $this->type,
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
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
