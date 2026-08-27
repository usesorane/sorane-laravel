<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\Endpoints;
use Ranetrace\Php\Config;
use Ranetrace\Php\Http\Endpoint;
use Throwable;

class RanetraceApiClient
{
    /**
     * Connection-phase timeout (seconds) for batch sends — fail fast on a dead
     * or unreachable host rather than tying up the worker for the full timeout.
     */
    protected const int CONNECT_TIMEOUT = 5;

    /**
     * The API base every ingest request here is built on.
     *
     * Resolved through the shared core's `base_url` rather than written down
     * again, so this SDK and `ranetrace/ranetrace-php` cannot end up addressing
     * different hosts. The trailing-slash trim mirrors the core's client for the
     * same reason: the two senders have to agree on what a configured value means.
     */
    protected string $apiUrl;

    public function __construct(
        protected ?string $apiKey = null
    ) {
        $this->apiKey = $apiKey ?? config('ranetrace.key');

        $baseUrl = CoreConfig::make()->get('base_url', Config::DEFAULT_BASE_URL);

        $this->apiUrl = is_string($baseUrl) && $baseUrl !== ''
            ? mb_rtrim($baseUrl, '/')
            : Config::DEFAULT_BASE_URL;
    }

    /**
     * Send one batch of captured items of the given type.
     *
     * The path, the wrapper key, the User-Agent feature and the timeout config
     * key all come from the shared endpoint table, so this SDK cannot address an
     * endpoint differently from `ranetrace/ranetrace-php`.
     *
     * @param  array<int, array>  $items
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When no endpoint is registered for the type.
     */
    public function sendBatchOfType(string $type, array $items): array
    {
        return $this->sendBatch(Endpoints::table()->get($type), $items);
    }

    /**
     * Send a batch of errors to Ranetrace.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendErrorBatch(array $errors): array
    {
        return $this->sendBatchOfType('errors', $errors);
    }

    /**
     * Send a batch of JavaScript errors to Ranetrace.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendJavaScriptErrorBatch(array $errors): array
    {
        return $this->sendBatchOfType('javascript_errors', $errors);
    }

    /**
     * Send a batch of events to Ranetrace.
     *
     * @param  array<int, array>  $events
     * @return array<string, mixed>
     */
    public function sendEventBatch(array $events): array
    {
        return $this->sendBatchOfType('events', $events);
    }

    /**
     * Send a batch of logs to Ranetrace.
     *
     * @param  array<int, array>  $logs
     * @return array<string, mixed>
     */
    public function sendLogBatch(array $logs): array
    {
        return $this->sendBatchOfType('logs', $logs);
    }

    /**
     * Send a batch of page visits to Ranetrace.
     *
     * @param  array<int, array>  $visits
     * @return array<string, mixed>
     */
    public function sendPageVisitBatch(array $visits): array
    {
        return $this->sendBatchOfType('page_visits', $visits);
    }

    /**
     * Send one batch to a feature store endpoint. Shared by all five feature
     * batch methods, which differ only in the endpoint they name.
     *
     * @param  array<int, array>  $items
     * @return array<string, mixed>
     */
    protected function sendBatch(Endpoint $endpoint, array $items): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse('API key not configured');
        }

        if (empty($items)) {
            return $this->formatErrorResponse('Empty batch provided');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => $endpoint->userAgent(Endpoints::SDK),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout((int) config(Endpoints::timeoutKey($endpoint), 10))
                ->post($this->apiUrl.$endpoint->path, [$endpoint->wrapper => $items]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Format an API response into the package's standard result shape.
     *
     * A failed response carries the API's error envelope
     * (`{success, message, error_code}`), so the message and the stable code
     * are lifted out here rather than at each call site, which then reports
     * what the API actually said instead of a generic "unknown error".
     *
     * @return array<string, mixed>
     */
    protected function formatResponse($response): array
    {
        $data = $response->json();
        $isValidData = is_array($data);

        $result = [
            'status' => $response->status(),
            'success' => $response->successful() && $isValidData,
            'data' => $isValidData ? $data : [],
            'headers' => [
                'retry-after' => $response->header('Retry-After'),
            ],
        ];

        if (! $isValidData && $response->successful()) {
            $result['error'] = 'Invalid response format';

            return $result;
        }

        if (! $response->successful() && $isValidData) {
            $errorCode = is_string($data['error_code'] ?? null) ? $data['error_code'] : null;
            $message = is_string($data['message'] ?? null) && $data['message'] !== ''
                ? $data['message']
                : null;

            if ($errorCode !== null) {
                $result['error_code'] = $errorCode;
            }

            if ($message !== null) {
                $result['error'] = $message;
            }
        }

        return $result;
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
