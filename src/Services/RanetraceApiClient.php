<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class RanetraceApiClient
{
    /**
     * Connection-phase timeout (seconds) for batch sends — fail fast on a dead
     * or unreachable host rather than tying up the worker for the full timeout.
     */
    protected const int CONNECT_TIMEOUT = 5;

    protected string $apiUrl = 'https://api.ranetrace.com/v1';

    protected int $timeout = 10;

    public function __construct(
        protected ?string $apiKey = null
    ) {
        $this->apiKey = $apiKey ?? config('ranetrace.key');
    }

    /**
     * Send a batch of errors to Ranetrace.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendErrorBatch(array $errors): array
    {
        return $this->sendBatch('/errors/store', 'errors', 'Ranetrace-Laravel/Errors/1.0', 'ranetrace.errors.timeout', $errors);
    }

    /**
     * Send a batch of JavaScript errors to Ranetrace.
     *
     * @param  array<int, array>  $errors
     * @return array<string, mixed>
     */
    public function sendJavaScriptErrorBatch(array $errors): array
    {
        return $this->sendBatch('/javascript-errors/store', 'javascript_errors', 'Ranetrace-Laravel/JavaScriptErrors/1.0', 'ranetrace.javascript_errors.timeout', $errors);
    }

    /**
     * Send a batch of events to Ranetrace.
     *
     * @param  array<int, array>  $events
     * @return array<string, mixed>
     */
    public function sendEventBatch(array $events): array
    {
        return $this->sendBatch('/events/store', 'events', 'Ranetrace-Laravel/Events/1.0', 'ranetrace.events.timeout', $events);
    }

    /**
     * Send a batch of logs to Ranetrace.
     *
     * @param  array<int, array>  $logs
     * @return array<string, mixed>
     */
    public function sendLogBatch(array $logs): array
    {
        return $this->sendBatch('/logs/store', 'logs', 'Ranetrace-Laravel/Logs/1.0', 'ranetrace.logging.timeout', $logs);
    }

    /**
     * Send a batch of page visits to Ranetrace.
     *
     * @param  array<int, array>  $visits
     * @return array<string, mixed>
     */
    public function sendPageVisitBatch(array $visits): array
    {
        return $this->sendBatch('/page-visits/store', 'page_visits', 'Ranetrace-Laravel/PageVisits/1.0', 'ranetrace.website_analytics.timeout', $visits);
    }

    /**
     * Every enabled monitor's verdict for the authenticated website — the
     * "which of my monitors needs a look" call.
     *
     * @return array<string, mixed>
     */
    public function getMonitorStatus(): array
    {
        return $this->getFromMcpApi('/monitors/status');
    }

    /**
     * Availability for the authenticated website: up or down, the 24h uptime
     * figure, and the recent outages behind it.
     *
     * @return array<string, mixed>
     */
    public function getUptimeStatus(): array
    {
        return $this->getFromMcpApi('/monitors/uptime/latest');
    }

    /**
     * Response-time health for the authenticated website: the 24h average and
     * where that time actually goes.
     *
     * @return array<string, mixed>
     */
    public function getPerformanceStats(): array
    {
        return $this->getFromMcpApi('/monitors/performance/latest');
    }

    /**
     * The latest Lighthouse audit for the authenticated website, plus the
     * previous run's scores for trend.
     *
     * @return array<string, mixed>
     */
    public function getLighthouseAudit(): array
    {
        return $this->getFromMcpApi('/monitors/lighthouse/latest');
    }

    /**
     * Certificate and HTTPS health for the authenticated website.
     *
     * @return array<string, mixed>
     */
    public function getCertificateStatus(): array
    {
        return $this->getFromMcpApi('/monitors/certificate/latest');
    }

    /**
     * Domain registration health for the authenticated website.
     *
     * @return array<string, mixed>
     */
    public function getDomainStatus(): array
    {
        return $this->getFromMcpApi('/monitors/domain/latest');
    }

    /**
     * The broken links the latest completed site audit found.
     *
     * @return array<string, mixed>
     */
    public function getBrokenLinks(): array
    {
        return $this->getFromMcpApi('/monitors/broken-links/latest');
    }

    /**
     * Get the latest errors from Ranetrace.
     *
     * @param  array{limit?: int, environment?: string, type?: string}  $params
     * @return array<string, mixed>
     */
    public function getLatestErrors(array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors', $params)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get a specific error by ID from Ranetrace.
     *
     * @return array<string, mixed>
     */
    public function getError(string $errorId): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/'.$errorId)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get error statistics from Ranetrace.
     *
     * @param  array{period?: string}  $params
     * @return array<string, mixed>
     */
    public function getErrorStats(array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/stats', $params)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Create a note on an error.
     *
     * @param  array{body: string}  $data
     * @return array<string, mixed>
     */
    public function createNote(string $errorId, array $data): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/errors/'.$errorId.'/notes', $data)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * List notes on an error.
     *
     * @param  array{limit?: int, offset?: int, author?: string, from?: string, to?: string, include_archived?: bool}  $params
     * @return array<string, mixed>
     */
    public function listNotes(string $errorId, array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/'.$errorId.'/notes', $params)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get a specific note on an error.
     *
     * @return array<string, mixed>
     */
    public function getNote(string $errorId, string $noteId): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/'.$errorId.'/notes/'.$noteId)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Update a note on an error.
     *
     * @param  array{body: string}  $data
     * @return array<string, mixed>
     */
    public function updateNote(string $errorId, string $noteId, array $data): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->put($this->apiUrl.'/errors/'.$errorId.'/notes/'.$noteId, $data)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Delete (archive) a note on an error.
     *
     * @return array<string, mixed>
     */
    public function deleteNote(string $errorId, string $noteId): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->delete($this->apiUrl.'/errors/'.$errorId.'/notes/'.$noteId)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Bulk create notes on an error.
     *
     * @param  array{notes: array<int, array{body: string}>}  $data
     * @return array<string, mixed>
     */
    public function createNotesBulk(string $errorId, array $data): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/errors/'.$errorId.'/notes/bulk', $data)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Resolve an error.
     *
     * @return array<string, mixed>
     */
    public function resolveError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'resolve', $type);
    }

    /**
     * Reopen a resolved error.
     *
     * @return array<string, mixed>
     */
    public function reopenError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'reopen', $type);
    }

    /**
     * Ignore an error.
     *
     * @return array<string, mixed>
     */
    public function ignoreError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'ignore', $type);
    }

    /**
     * Unignore an error.
     *
     * @return array<string, mixed>
     */
    public function unignoreError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'unignore', $type);
    }

    /**
     * Snooze an error.
     *
     * @param  array{duration?: string, until?: string}  $data
     * @return array<string, mixed>
     */
    public function snoozeError(string $errorId, array $data, string $type = 'php'): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/errors/'.$errorId.'/snooze', [...$data, 'type' => $type])
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Unsnooze an error.
     *
     * @return array<string, mixed>
     */
    public function unsnoozeError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'unsnooze', $type);
    }

    /**
     * Delete (archive) an error.
     *
     * @return array<string, mixed>
     */
    public function deleteError(string $errorId, string $type = 'php'): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->delete($this->apiUrl.'/errors/'.$errorId, ['type' => $type])
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Get activity log for an error.
     *
     * @param  array{limit?: int, offset?: int}  $params
     * @return array<string, mixed>
     */
    public function getErrorActivity(string $errorId, array $params = [], string $type = 'php'): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $queryParams = array_merge($params, ['type' => $type]);

            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/'.$errorId.'/activity', $queryParams)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Bulk resolve errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkResolveErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'resolve', $type);
    }

    /**
     * Bulk reopen errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkReopenErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'reopen', $type);
    }

    /**
     * Bulk ignore errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkIgnoreErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'ignore', $type);
    }

    /**
     * Bulk delete (archive) errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkDeleteErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'delete', $type);
    }

    /**
     * Search errors with advanced filtering.
     *
     * @param  array{
     *     type?: string,
     *     status?: string|array,
     *     environments?: array,
     *     exclude_environments?: array,
     *     first_occurred_period?: string,
     *     first_occurred_from?: string,
     *     first_occurred_to?: string,
     *     last_occurred_period?: string,
     *     last_occurred_from?: string,
     *     last_occurred_to?: string,
     *     occurrence_level?: string,
     *     min_occurrences?: int,
     *     max_occurrences?: int,
     *     sort?: string,
     *     direction?: string,
     *     limit?: int,
     *     cursor?: string,
     *     include_archived?: bool
     * }  $params
     * @return array<string, mixed>
     */
    public function searchErrors(array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->get($this->apiUrl.'/errors/search', $params)
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Restore a soft-deleted error.
     *
     * @return array<string, mixed>
     */
    public function restoreError(string $errorId, string $type = 'php'): array
    {
        return $this->performErrorAction($errorId, 'restore', $type);
    }

    /**
     * Bulk restore soft-deleted errors.
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    public function bulkRestoreErrors(array $errorIds, string $type = 'php'): array
    {
        return $this->performBulkErrorAction($errorIds, 'restore', $type);
    }

    /**
     * Perform a single error action (resolve, reopen, ignore, unignore, unsnooze).
     *
     * @return array<string, mixed>
     */
    protected function performErrorAction(string $errorId, string $action, string $type): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/errors/'.$errorId.'/'.$action, ['type' => $type])
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Perform a bulk error action (resolve, reopen, ignore, delete).
     *
     * @param  array<int, string>  $errorIds
     * @return array<string, mixed>
     */
    protected function performBulkErrorAction(array $errorIds, string $action, string $type): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->post($this->apiUrl.'/errors/bulk/'.$action, [
                    'error_ids' => $errorIds,
                    'type' => $type,
                ])
            );

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Execute a request with retry logic for transient failures.
     *
     * @param  callable(): Response  $request
     *
     * @throws Throwable
     */
    protected function executeWithRetry(callable $request, int $maxAttempts = 3): Response
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                $response = $request();

                // Retry on 5xx server errors
                if ($response->serverError() && $attempt < $maxAttempts - 1) {
                    $attempt++;
                    $this->sleep($this->calculateBackoff($attempt));

                    continue;
                }

                return $response;
            } catch (ConnectionException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $maxAttempts) {
                    $this->sleep($this->calculateBackoff($attempt));
                }
            }
        }

        throw $lastException ?? new RuntimeException('Request failed after retries');
    }

    /**
     * Calculate exponential backoff delay in milliseconds.
     */
    protected function calculateBackoff(int $attempt): int
    {
        // Base delay: 100ms, 200ms, 400ms (exponential)
        return (int) (100 * pow(2, $attempt - 1));
    }

    /**
     * Sleep for the given number of milliseconds.
     * Extracted for testability.
     */
    protected function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Send one batch to a feature store endpoint. Shared by all five feature
     * batch methods — they differ only in path, wrapper key, User-Agent and timeout.
     *
     * @param  array<int, array>  $items
     * @return array<string, mixed>
     */
    protected function sendBatch(string $path, string $wrapperKey, string $userAgent, string $timeoutConfigKey, array $items): array
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
                    'User-Agent' => $userAgent,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout((int) config($timeoutConfigKey, 10))
                ->post($this->apiUrl.$path, [$wrapperKey => $items]);

            return $this->formatResponse($response);
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e->getMessage());
        }
    }

    /**
     * Perform a read-only GET against an MCP API endpoint.
     *
     * The monitor endpoints take no parameters — the token already scopes the
     * call to one website — so this only varies by path. Shared so a new
     * monitor endpoint cannot drift from the auth header, retry behavior and
     * timeouts the other read calls use.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function getFromMcpApi(string $path, array $params = []): array
    {
        if (empty($this->apiKey)) {
            return $this->formatErrorResponse($this->mcpTokenRequiredMessage(null));
        }

        try {
            $response = $this->executeWithRetry(fn () => Http::withToken($this->apiKey)
                ->withHeaders([
                    'User-Agent' => 'Ranetrace-Laravel/MCP/1.0',
                    'Accept' => 'application/json',
                    'Ranetrace-API-Version' => '1.0',
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeout)
                ->get($this->apiUrl.$path, $params)
            );

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
     * are lifted out here rather than in each of the tools: every tool, the
     * error and note ones included, then reports what the API actually said
     * instead of a generic "unknown error".
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

            $error = $this->failureMessage($errorCode, $message);

            if ($error !== null) {
                $result['error'] = $error;
            }
        }

        return $result;
    }

    /**
     * The message a failed API response should surface to the calling agent.
     *
     * Two error codes are answered specifically rather than passed through as
     * one more failure: `MCP_TOKEN_REQUIRED` is a setup problem the agent can
     * fix if it is told how, and `MONITOR_DISABLED` is a true answer about the
     * account ("nobody is watching this") that must reach the agent intact.
     * Everything else is the API's own message, or null so the calling tool
     * keeps its own wording.
     */
    protected function failureMessage(?string $errorCode, ?string $message): ?string
    {
        return match ($errorCode) {
            'MCP_TOKEN_REQUIRED' => $this->mcpTokenRequiredMessage($message),
            'MONITOR_DISABLED' => $message,
            default => $message,
        };
    }

    /**
     * Turn a missing/insufficient MCP token into instructions an agent can act
     * on. The API's own message names the ability that was required, so it
     * leads; the "create one" sentence is only added when the API did not
     * already say it, and where to put the token is always appended (the API
     * cannot know it is a Laravel app talking to it).
     */
    protected function mcpTokenRequiredMessage(?string $message): string
    {
        $parts = [$message ?? 'This endpoint requires a Ranetrace MCP token.'];

        if (! str_contains(mb_strtolower($parts[0]), '/mcp')) {
            $parts[] = "Create one on the website's /mcp page in Ranetrace.";
        }

        $parts[] = 'Set it as RANETRACE_MCP_TOKEN in your MCP client\'s server entry (or in .env), then restart the MCP server.';

        return implode(' ', $parts);
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
