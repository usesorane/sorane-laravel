<?php

declare(strict_types=1);

namespace Sorane\Laravel\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class SoraneApiClient
{
    protected string $apiUrl = 'https://api.sorane.io/v1';

    protected int $timeout = 10;

    public function __construct(
        protected ?string $apiKey = null
    ) {
        $this->apiKey = $apiKey ?? config('sorane.key');
    }

    /**
     * Send a batch of errors to Sorane.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendErrorBatch(array $errors): array
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

        try {
            $timeout = config('sorane.errors.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/errors/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/errors/store', [
                    'errors' => $errors,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : [
                    'success' => true,
                    'received' => count($errors),
                    'processed' => count($errors),
                ];
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
     * Send a batch of JavaScript errors to Sorane.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendJavaScriptErrorBatch(array $errors): array
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

        try {
            $timeout = config('sorane.javascript_errors.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/javascript-errors/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/javascript-errors/store', [
                    'errors' => $errors,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : [
                    'success' => true,
                    'received' => count($errors),
                    'processed' => count($errors),
                ];
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
            $timeout = config('sorane.events.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/events/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/events/store', [
                    'events' => $events,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : [
                    'success' => true,
                    'received' => count($events),
                    'processed' => count($events),
                ];
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
            $timeout = config('sorane.logging.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/logs/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/logs/store', [
                    'logs' => $logs,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : [
                    'success' => true,
                    'received' => count($logs),
                    'processed' => count($logs),
                ];
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
            $timeout = config('sorane.website_analytics.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/page-visits/1.0',
                    'Content-Type' => 'application/json',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/page-visits/store', [
                    'visits' => $visits,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : [
                    'success' => true,
                    'received' => count($visits),
                    'processed' => count($visits),
                ];
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
