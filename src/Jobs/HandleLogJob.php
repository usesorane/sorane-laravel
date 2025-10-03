<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Sorane\Laravel\Services\SoraneBatchBuffer;

class HandleLogJob implements ShouldQueue
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

        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $buffer->addItem('logs', $payload);
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

    /**
     * Handle job failure after all retries exhausted.
     * Logs to single channel (not Sorane) to prevent infinite error loops.
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('single')
            ->critical('Sorane job failed after all retries', [
                'job_class' => static::class,
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
    }
}
