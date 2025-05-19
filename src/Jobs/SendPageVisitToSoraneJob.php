<?php

namespace Sorane\ErrorReporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendPageVisitToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $visitData;

    public function __construct(array $visitData)
    {
        $this->visitData = $visitData;

        // Optionally assign queue name from config
        $this->onQueue(config('sorane.analytics.queue', 'default'));
    }

    public function handle(): void
    {
        $apiKey = config('sorane.key');

        if (empty($apiKey)) {
            Log::warning('Sorane API key is not set. Visit data will not be sent.');

            return;
        }

        $payload = $this->filterPayload($this->visitData);

        try {
            Http::withToken($apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Analytics/1.0',
                ])
                ->timeout(5)
                ->post('https://api.sorane.io/v1/analytics/visit', $payload);
        } catch (\Throwable $e) {
            Log::warning('Failed to send visit data to Sorane: '.$e->getMessage());
        }
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
