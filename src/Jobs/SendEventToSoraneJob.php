<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Sorane\Laravel\Services\SoraneBatchBuffer;

class SendEventToSoraneJob extends BaseSoraneJob
{
    public function __construct(
        protected array $eventData
    ) {
        $this->assignQueue();
    }

    public function handle(SoraneBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->eventData);

        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $buffer->addItem('events', $payload);
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
