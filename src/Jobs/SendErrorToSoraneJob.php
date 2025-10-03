<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sorane\Laravel\Services\SoraneBatchBuffer;

class SendErrorToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected array $errorData
    ) {
        // Optionally assign queue name from config
        $this->onQueue(config('sorane.errors.queue_name', 'default'));
    }

    public function handle(SoraneBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->errorData);

        // Add to buffer
        $buffer->addItem('errors', $payload);

        // Check if we should trigger a batch flush
        $batchSize = config('sorane.errors.batch.size', 50);
        if ($buffer->count('errors') >= $batchSize) {
            SendBatchToSoraneJob::dispatch('errors');
        }
    }

    protected function filterPayload(array $data): array
    {
        $allowedKeys = [
            'for',
            'message',
            'file',
            'line',
            'type',
            'environment',
            'trace',
            'headers',
            'context',
            'highlight_line',
            'user',
            'time',
            'url',
            'method',
            'php_version',
            'laravel_version',
            'is_console',
            'console_command',
            'console_arguments',
            'console_options',
        ];

        return collect($data)
            ->only($allowedKeys)
            ->toArray();
    }
}
