<?php

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sorane\Laravel\Services\SoraneApiClient;

class SendEventToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $eventData;

    public function __construct(array $eventData)
    {
        $this->eventData = $eventData;

        // Optionally assign queue name from config
        $this->onQueue(config('sorane.events.queue_name', 'default'));
    }

    public function handle(SoraneApiClient $client): void
    {
        $payload = $this->filterPayload($this->eventData);

        $client->sendEvent($payload);
    }

    protected function filterPayload(array $data): array
    {
        $allowedKeys = [
            'event_name',
            'properties',
            'user',
            'timestamp',
            'url',
            'user_agent_hash',
            'session_id_hash',
        ];

        return collect($data)
            ->only($allowedKeys)
            ->toArray();
    }
}
