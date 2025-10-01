<?php

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for all Sorane jobs providing common functionality.
 */
abstract class BaseSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Get the config path for this job type.
     */
    abstract protected function getConfigPath(): string;

    /**
     * Get the allowed keys for payload filtering.
     *
     * @return array<int, string>
     */
    abstract protected function getAllowedKeys(): array;

    /**
     * Filter payload to only include allowed keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function filterPayload(array $data): array
    {
        return collect($data)
            ->only($this->getAllowedKeys())
            ->toArray();
    }

    /**
     * Assign the job to the configured queue.
     */
    protected function assignQueue(): void
    {
        $queueName = config($this->getConfigPath().'.queue_name', 'default');
        $this->onQueue($queueName);
    }
}
