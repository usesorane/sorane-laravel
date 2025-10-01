<?php

namespace Sorane\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sorane\Laravel\Services\SoraneApiClient;

class SendPageVisitToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $visitData;

    public function __construct(array $visitData)
    {
        $this->visitData = $visitData;

        // Optionally assign queue name from config
        $this->onQueue(config('sorane.website_analytics.queue', 'default'));
    }

    public function handle(SoraneApiClient $client): void
    {
        $payload = $this->filterPayload($this->visitData);

        $client->sendPageVisit($payload);
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
