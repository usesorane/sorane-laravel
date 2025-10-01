<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Sorane\Laravel\Services\SoraneApiClient;

class SendEventToSoraneJob extends BaseSoraneJob
{
    public function __construct(
        protected array $eventData
    ) {
        $this->assignQueue();
    }

    public function handle(SoraneApiClient $client): void
    {
        $payload = $this->filterPayload($this->eventData);

        $client->sendEvent($payload);
    }

    public function getEventData(): array
    {
        return $this->eventData;
    }

    protected function getConfigPath(): string
    {
        return 'sorane.events';
    }

    protected function getAllowedKeys(): array
    {
        return [
            'event_name',
            'properties',
            'user',
            'timestamp',
            'url',
            'user_agent_hash',
            'session_id_hash',
        ];
    }
}
