<?php

namespace Sorane\ErrorReporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    public function handle(): void
    {
        $apiKey = config('sorane.key');

        if (empty($apiKey)) {
            Log::warning('Sorane API key is not set. Event data will not be sent.');
            return;
        }

        $payload = $this->filterPayload($this->eventData);

        try {
            Http::withToken($apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Events/1.0',
                ])
                ->timeout(5)
                ->post('https://api.sorane.io/v1/analytics/events', $payload);
        } catch (\Throwable $e) {
            Log::warning('Failed to send event data to Sorane: ' . $e->getMessage());
        }
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
