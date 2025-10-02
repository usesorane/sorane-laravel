<?php

declare(strict_types=1);

namespace Sorane\Laravel\Services;

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
        $this->timeout = config('sorane.error_reporting.timeout', 5);
    }

    public function sendError(array $errorData, string $type): bool
    {
        if (empty($this->apiKey)) {
            Log::warning('Sorane API key not configured. Set SORANE_KEY in your .env file to enable error tracking.');

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
            Log::warning('Sorane API key not configured. Set SORANE_KEY in your .env file to enable event tracking.');

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
            Log::warning('Sorane API key not configured. Set SORANE_KEY in your .env file to enable website analytics.');

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

            if ($response->successful()) {
                $responseData = $response->json();
                if (isset($responseData['success']) && $responseData['success'] === false) {
                    Log::warning('Sorane page visit API error: '.($responseData['message'] ?? 'Unknown error'), [
                        'error_code' => $responseData['error_code'] ?? null,
                    ]);

                    return false;
                }

                return true;
            }

            Log::error('Sorane page visit API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning('Failed to send page visit data to Sorane: '.$e->getMessage());

            return false;
        }
    }

    public function sendErrorReport(array $errorData): bool
    {
        if (empty($this->apiKey)) {
            Log::warning('Sorane API key not configured. Set SORANE_KEY in your .env file to enable error reporting.');

            return false;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/error-reporting/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/report', $errorData);

            if ($response->successful()) {
                $responseData = $response->json();
                if (isset($responseData['success']) && $responseData['success'] === false) {
                    Log::warning('Sorane error reporting API error: '.($responseData['message'] ?? 'Unknown error'), [
                        'error_code' => $responseData['error_code'] ?? null,
                        'errors' => $responseData['errors'] ?? null,
                    ]);

                    return false;
                }

                return true;
            }

            Log::error('Sorane error reporting API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning('Failed to send error report to Sorane: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Send a batch of events to Sorane.
     *
     * @param  array<int, array>  $events
     * @return array<string, mixed>
     */
    public function sendEventBatch(array $events): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API key not configured',
            ];
        }

        if (empty($events)) {
            return [
                'success' => true,
                'received' => 0,
                'processed' => 0,
            ];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/events-batch/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout * 2) // Double timeout for batches
                ->post($this->apiUrl.'/events/store-batch', [
                    'events' => $events,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'message' => "API request failed with status {$response->status()}",
                'status' => $response->status(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a batch of logs to Sorane.
     *
     * @param  array<int, array>  $logs
     * @return array<string, mixed>
     */
    public function sendLogBatch(array $logs): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API key not configured',
            ];
        }

        if (empty($logs)) {
            return [
                'success' => true,
                'received' => 0,
                'processed' => 0,
            ];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/logs-batch/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout * 2)
                ->post($this->apiUrl.'/logs/store-batch', [
                    'logs' => $logs,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'message' => "API request failed with status {$response->status()}",
                'status' => $response->status(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a batch of page visits to Sorane.
     *
     * @param  array<int, array>  $visits
     * @return array<string, mixed>
     */
    public function sendPageVisitBatch(array $visits): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API key not configured',
            ];
        }

        if (empty($visits)) {
            return [
                'success' => true,
                'received' => 0,
                'processed' => 0,
            ];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/page-visits-batch/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout * 2)
                ->post($this->apiUrl.'/page-visits/store-batch', [
                    'visits' => $visits,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'message' => "API request failed with status {$response->status()}",
                'status' => $response->status(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a batch of errors to Sorane.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendErrorBatch(array $errors, string $type): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'API key not configured',
            ];
        }

        if (empty($errors)) {
            return [
                'success' => true,
                'received' => 0,
                'processed' => 0,
            ];
        }

        $endpoint = match ($type) {
            'javascript' => '/javascript-errors/store-batch',
            'log' => '/logs/store-batch',
            default => '/errors/store-batch',
        };

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => "Sorane-Laravel/{$type}-batch/1.0",
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout * 2)
                ->post($this->apiUrl.$endpoint, [
                    'errors' => $errors,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'success' => false,
                'message' => "API request failed with status {$response->status()}",
                'status' => $response->status(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
