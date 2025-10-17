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
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($errors)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.errors.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/Errors/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/errors/store', [
                    'errors' => $errors,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
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
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($errors)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.javascript_errors.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/JavaScriptErrors/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/javascript-errors/store', [
                    'javascript_errors' => $errors,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
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
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($events)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.events.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/Events/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/events/store', [
                    'events' => $events,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
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
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($logs)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.logging.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/Logs/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/logs/store', [
                    'logs' => $logs,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
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
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($visits)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $timeout = config('sorane.website_analytics.timeout', 10);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Sorane-Laravel/PageVisits/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Sorane-API-Version' => '1.0',
                ])
                ->timeout($timeout)
                ->post($this->apiUrl.'/page-visits/store', [
                    'page_visits' => $visits,
                ]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Format API response for consistent handling.
     *
     * @param  \Illuminate\Http\Client\Response  $response
     * @return array<string, mixed>
     */
    protected function formatResponse($response): array
    {
        $data = $response->json();

        return [
            'status' => $response->status(),
            'success' => $response->successful(),
            'data' => is_array($data) ? $data : [],
            'headers' => [
                'retry-after' => $response->header('Retry-After'),
            ],
        ];
    }

    /**
     * Format error response for network/exception errors.
     *
     * @return array<string, mixed>
     */
    protected function formatErrorResponse(string $message): array
    {
        return [
            'status' => 0,
            'success' => false,
            'data' => [],
            'error' => $message,
            'headers' => [],
        ];
    }
}
