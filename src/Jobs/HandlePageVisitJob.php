<?php

declare(strict_types=1);

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sorane\Laravel\Services\SoraneBatchBuffer;
use Sorane\Laravel\Support\InternalLogger;
use Throwable;

class HandlePageVisitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected array $visitData
    ) {
        // Optionally assign queue name from config
        $this->onQueue(config('sorane.website_analytics.queue_name', 'default'));
    }

    public function handle(SoraneBatchBuffer $buffer): void
    {
        $payload = $this->filterPayload($this->visitData);

        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $buffer->addItem('page_visits', $payload);
    }

    /**
     * Handle job failure after all retries exhausted.
     * Logs to 'sorane_internal' channel to prevent infinite error loops (never logs to Sorane).
     */
    public function failed(Throwable $exception): void
    {
        // Use 'sorane_internal' channel
        // to prevent infinite loops by bypassing Sorane's own capture
        InternalLogger::critical('Sorane job failed after all retries', [
            'job_class' => static::class,
            'exception' => $exception->getMessage(),
        ]);
    }

    protected function filterPayload(array $data): array
    {
        $keys = [
            'url',
            'path',
            'timestamp',
            'referrer',
            'country_code',
            'device_type',
            'browser_name',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'session_id_hash',
            'user_agent_hash',
            'human_probability_score',
            'human_probability_reasons',
        ];

        // Development mode that preserves the unhashed user agent
        // Using this setting in production is pointless and unsafe
        // Sorane will ignore non-hashed user agents
        $preserveUserAgent = config('sorane.website_analytics.debug.preserve_user_agent', false);
        if ($preserveUserAgent) {
            $keys[] = 'user_agent';
        }

        return collect($data)
            ->only($keys)
            ->toArray();
    }
}
