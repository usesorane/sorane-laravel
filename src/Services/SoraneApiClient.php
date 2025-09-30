<?php

namespace Sorane\ErrorReporting\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SoraneApiClient
{
    protected string $apiUrl = 'https://api.sorane.io/v1';

    protected int $timeout = 5;

    public function __construct(
        protected ?string $apiKey = null
    ) {
        $this->apiKey = $apiKey ?? config('sorane.key');
    }

    public function sendError(array $errorData, string $type): bool
    {
        if (empty($this->apiKey)) {
            Log::warning('Sorane API key is not set. Error data will not be sent.');

            return false;
        }

        $endpoint = match ($type) {
            'javascript' => '/javascript-errors/store',
            'log' => '/logs/store',
            default => '/errors/store',
        };

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => "Sorane-Laravel/{$type}/1.0",
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->apiUrl.$endpoint, $errorData);

            if ($response->successful()) {
                $responseData = $response->json();
                if (isset($responseData['success']) && $responseData['success'] === false) {
                    Log::warning("Sorane {$type} error API error: ".($responseData['message'] ?? 'Unknown error'), [
                        'error_code' => $responseData['error_code'] ?? null,
                    ]);

                    return false;
                }

                return true;
            }

            Log::error("Sorane {$type} error API request failed", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning("Failed to send {$type} error data to Sorane: ".$e->getMessage());

            return false;
        }
    }

    public function sendEvent(array $eventData): bool
    {
        if (empty($this->apiKey)) {
            Log::warning('Sorane API key is not set. Event data will not be sent.');

            return false;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/events/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/events/store', $eventData);

            if ($response->successful()) {
                $responseData = $response->json();
                if (isset($responseData['success']) && $responseData['success'] === false) {
                    Log::warning('Sorane event API error: '.($responseData['message'] ?? 'Unknown error'), [
                        'error_code' => $responseData['error_code'] ?? null,
                    ]);

                    return false;
                }

                return true;
            }

            Log::error('Sorane event API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning('Failed to send event data to Sorane: '.$e->getMessage());

            return false;
        }
    }

    public function sendPageVisit(array $visitData): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/analytics/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/page-visits/store', $visitData);

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('Failed to send page visit data to Sorane: '.$e->getMessage());

            return false;
        }
    }
}
