<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sorane\Laravel\Services\SoraneApiClient;
use Sorane\Laravel\Services\SoraneBatchBuffer;

class SendLogToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected array $logData
    ) {
        // Optionally assign queue name from config
        $this->onQueue(config('sorane.logging.queue_name', 'default'));
    }

    public function handle(SoraneBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->logData);

        // Add to buffer
        $buffer->addItem('logs', $payload);

        // Check if we should trigger a batch flush
        $batchSize = config('sorane.batch.logs.size', config('sorane.batch.size', 100));
        if ($buffer->count('logs') >= $batchSize) {
            SendBatchToSoraneJob::dispatch('logs');
        }
    }

    public function getLogData(): array
    {
        return $this->logData;
    }

    protected function filterPayload(array $data): array
    {
        $allowedKeys = [
            'level',
            'message',
            'context',
            'channel',
            'timestamp',
            'extra',
        ];

        return collect($data)
            ->only($allowedKeys)
            ->toArray();
    }
}
