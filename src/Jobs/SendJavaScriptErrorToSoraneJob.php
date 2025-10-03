<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sorane\Laravel\Services\SoraneBatchBuffer;

class SendJavaScriptErrorToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected array $errorData
    ) {
        // Optionally assign queue name from config
        $this->onQueue(config('sorane.javascript_errors.queue_name', 'default'));
    }

    public function handle(SoraneBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->errorData);

        // Add to buffer
        $buffer->addItem('javascript_errors', $payload);

        // Check if we should trigger a batch flush
        $batchSize = config('sorane.batch.javascript_errors.size', config('sorane.batch.size', 100));
        if ($buffer->count('javascript_errors') >= $batchSize) {
            SendBatchToSoraneJob::dispatch('javascript_errors');
        }
    }

    protected function filterPayload(array $data): array
    {
        $allowedKeys = [
            'message',
            'stack',
            'type',
            'filename',
            'line',
            'column',
            'user_agent',
            'url',
            'timestamp',
            'environment',
            'user_id',
            'session_id',
            'breadcrumbs',
            'context',
            'browser_info',
        ];

        return collect($data)
            ->only($allowedKeys)
            ->toArray();
    }
}
