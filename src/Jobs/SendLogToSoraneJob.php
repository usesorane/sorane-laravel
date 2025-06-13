<?php

namespace Sorane\ErrorReporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendLogToSoraneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $logData;

    public function __construct(array $logData)
    {
        $this->logData = $logData;

        // Optionally assign queue name from config
        $this->onQueue(config('sorane.logging.queue_name', 'default'));
    }

    public function handle(): void
    {
        $apiKey = config('sorane.key');

        if (empty($apiKey)) {
            Log::warning('Sorane API key is not set. Log data will not be sent.');

            return;
        }

        $payload = $this->filterPayload($this->logData);

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Logging/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(5)
                ->post('https://api.sorane.io/v1/logs/store', $payload);

            // Enhanced error handling for new API response format
            if ($response->successful()) {
                $responseData = $response->json();
                if (isset($responseData['success']) && $responseData['success'] === false) {
                    Log::warning('Sorane logging API error: '.($responseData['message'] ?? 'Unknown error'), [
                        'error_code' => $responseData['error_code'] ?? null,
                    ]);
                }
            } else {
                Log::error('Sorane logging API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send log data to Sorane: '.$e->getMessage());
        }
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
