<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sorane\Laravel\Services\SoraneApiClient;

class SendLogToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected array $logData
    ) {
        // Optionally assign queue name from config
        $this->onQueue(config('sorane.logging.queue_name', 'default'));
    }

    public function handle(SoraneApiClient $client): void
    {
        $payload = $this->filterPayload($this->logData);

        $client->sendError($payload, 'log');
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
