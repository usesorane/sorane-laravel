<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ranetrace\Laravel\Mcp\Tools\Concerns\FormatsUntrustedText;
use Ranetrace\Laravel\Services\RanetraceApiClient;

#[IsReadOnly]
class SearchErrorsTool extends Tool
{
    use FormatsUntrustedText;

    protected const VALID_PERIODS = ['1h', '6h', '24h', '7d', '30d', '90d'];

    protected const VALID_OCCURRENCE_LEVELS = ['critical', 'frequent', 'moderate', 'rare'];

    protected const VALID_STATUSES = ['open', 'resolved', 'ignored', 'snoozed', 'active', 'closed', 'all'];

    protected const VALID_SORTS = ['last_occurred', 'first_occurred', 'occurrence_count'];

    protected const MAX_LIMIT = 100;

    protected const DEFAULT_LIMIT = 50;

    /**
     * The tool's description.
     */
    protected string $description = 'Search for errors matching specific criteria with advanced filtering. By default, returns only open errors. Use status="all" to include all statuses. Supports filtering by type, environment, date ranges, occurrence counts, and pagination.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $params = $this->buildParams($request);

        $validationError = $this->validateParams($params, $request);
        if ($validationError !== null) {
            return Response::error($validationError);
        }

        $result = $this->client->searchErrors($params);

        if (! $result['success']) {
            return $this->handleErrorResponse($result);
        }

        return Response::text($this->formatResponse($result['data']));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('Filter by error type: "php", "javascript", or "all" (default: "all").')
                ->enum(['php', 'javascript', 'js', 'all']),
            'status' => $schema->string()
                ->description('Status filter: "open" (default), "resolved", "ignored", "snoozed", "active", "closed", or "all". Can be comma-separated for multiple.')
                ->default('open'),
            'environments' => $schema->array()
                ->description('Array of environments to include (case-insensitive).'),
            'exclude_environments' => $schema->array()
                ->description('Array of environments to exclude (case-insensitive). Cannot be used with "environments".'),
            'first_occurred_period' => $schema->string()
                ->description('Preset period for first occurrence: "1h", "6h", "24h", "7d", "30d", "90d".')
                ->enum(self::VALID_PERIODS),
            'first_occurred_from' => $schema->string()
                ->description('ISO 8601 datetime for first occurrence start.'),
            'first_occurred_to' => $schema->string()
                ->description('ISO 8601 datetime for first occurrence end.'),
            'last_occurred_period' => $schema->string()
                ->description('Preset period for last occurrence: "1h", "6h", "24h", "7d", "30d", "90d".')
                ->enum(self::VALID_PERIODS),
            'last_occurred_from' => $schema->string()
                ->description('ISO 8601 datetime for last occurrence start.'),
            'last_occurred_to' => $schema->string()
                ->description('ISO 8601 datetime for last occurrence end.'),
            'occurrence_level' => $schema->string()
                ->description('Preset occurrence level: "critical" (>100), "frequent" (>20), "moderate" (>5), "rare" (<5).')
                ->enum(self::VALID_OCCURRENCE_LEVELS),
            'min_occurrences' => $schema->integer()
                ->description('Minimum occurrence count.'),
            'max_occurrences' => $schema->integer()
                ->description('Maximum occurrence count.'),
            'sort' => $schema->string()
                ->description('Sort field: "last_occurred" (default), "first_occurred", "occurrence_count".')
                ->enum(self::VALID_SORTS),
            'direction' => $schema->string()
                ->description('Sort direction: "asc" or "desc".')
                ->enum(['asc', 'desc']),
            'limit' => $schema->integer()
                ->description('Results per page (default 50, max 100).')
                ->default(self::DEFAULT_LIMIT),
            'cursor' => $schema->string()
                ->description('Pagination cursor for next/previous page.'),
            'include_archived' => $schema->boolean()
                ->description('Include soft-deleted errors (default false).'),
        ];
    }

    /**
     * Build params from request.
     *
     * @return array<string, mixed>
     */
    protected function buildParams(Request $request): array
    {
        $params = [];

        // Type normalization
        $type = $request->get('type');
        if ($type !== null) {
            $params['type'] = $type === 'js' ? 'javascript' : $type;
        }

        // Status can be string or array - default to 'open' if not provided, skip if 'all'
        $status = $request->get('status') ?? 'open';
        if ($status !== 'all') {
            $params['status'] = $status;
        }

        // Environment filters
        $environments = $request->get('environments');
        if (! empty($environments) && is_array($environments)) {
            $params['environments'] = $environments;
        }

        $excludeEnvironments = $request->get('exclude_environments');
        if (! empty($excludeEnvironments) && is_array($excludeEnvironments)) {
            $params['exclude_environments'] = $excludeEnvironments;
        }

        // Date filters
        $dateFields = [
            'first_occurred_period',
            'first_occurred_from',
            'first_occurred_to',
            'last_occurred_period',
            'last_occurred_from',
            'last_occurred_to',
        ];

        foreach ($dateFields as $field) {
            $value = $request->get($field);
            if ($value !== null && $value !== '') {
                $params[$field] = $value;
            }
        }

        // Occurrence filters
        $occurrenceLevel = $request->get('occurrence_level');
        if ($occurrenceLevel !== null) {
            $params['occurrence_level'] = $occurrenceLevel;
        }

        $minOccurrences = $request->get('min_occurrences');
        if ($minOccurrences !== null) {
            $params['min_occurrences'] = (int) $minOccurrences;
        }

        $maxOccurrences = $request->get('max_occurrences');
        if ($maxOccurrences !== null) {
            $params['max_occurrences'] = (int) $maxOccurrences;
        }

        // Sorting
        $sort = $request->get('sort');
        if ($sort !== null) {
            $params['sort'] = $sort;
        }

        $direction = $request->get('direction');
        if ($direction !== null) {
            $params['direction'] = $direction;
        }

        // Pagination
        $limit = $request->get('limit');
        if ($limit !== null) {
            $params['limit'] = min((int) $limit, self::MAX_LIMIT);
        }

        $cursor = $request->get('cursor');
        if ($cursor !== null && $cursor !== '') {
            $params['cursor'] = $cursor;
        }

        // Include archived
        $includeArchived = $request->get('include_archived');
        if ($includeArchived === true) {
            $params['include_archived'] = true;
        }

        return $params;
    }

    /**
     * Validate params.
     *
     * @param  array<string, mixed>  $params
     */
    protected function validateParams(array $params, Request $request): ?string
    {
        // Cannot use both environments and exclude_environments
        if (isset($params['environments']) && isset($params['exclude_environments'])) {
            return 'Cannot use both "environments" and "exclude_environments" in the same request.';
        }

        // Validate min <= max occurrences
        $minOccurrences = $request->get('min_occurrences');
        $maxOccurrences = $request->get('max_occurrences');
        if ($minOccurrences !== null && $maxOccurrences !== null && (int) $minOccurrences > (int) $maxOccurrences) {
            return 'min_occurrences cannot be greater than max_occurrences.';
        }

        // Validate limit
        $limit = $request->get('limit');
        if ($limit !== null && (int) $limit > self::MAX_LIMIT) {
            return 'limit cannot exceed '.self::MAX_LIMIT.'.';
        }

        return null;
    }

    /**
     * Handle error responses from the API.
     *
     * @param  array<string, mixed>  $result
     */
    protected function handleErrorResponse(array $result): Response
    {
        $errorMessage = $result['error'] ?? 'Unknown error occurred';

        return match ($result['status']) {
            403 => Response::error("Access denied: {$errorMessage}"),
            422 => Response::error("Validation failed: {$errorMessage}"),
            default => Response::error("Failed to search errors: {$errorMessage}"),
        };
    }

    /**
     * Format the success response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatResponse(array $data): string
    {
        $errors = $data['errors'] ?? [];
        $meta = $data['meta'] ?? [];

        $totalCount = $meta['total_count'] ?? count($errors);
        $countByStatus = $meta['count_by_status'] ?? [];
        $nextCursor = $meta['next_cursor'] ?? null;
        $prevCursor = $meta['prev_cursor'] ?? null;

        if (empty($errors)) {
            return "# Search Results\n\nNo errors found matching the specified criteria.";
        }

        $output = "# Search Results\n\n";
        $output .= "**Total Count:** {$totalCount}\n";

        // Status breakdown
        if (! empty($countByStatus)) {
            $output .= "\n## Status Breakdown\n";
            foreach ($countByStatus as $status => $count) {
                $output .= '- **'.ucfirst($status).":** {$count}\n";
            }
        }

        $output .= "\n## Errors (".count($errors)." shown)\n\n";
        $output .= $this->untrustedTextNotice()."\n\n";

        foreach ($errors as $index => $error) {
            $output .= $this->formatError($index + 1, $error);
        }

        // Pagination info
        if ($nextCursor !== null || $prevCursor !== null) {
            $output .= "\n## Pagination\n";
            if ($prevCursor !== null) {
                $output .= "- **Previous Cursor:** `{$prevCursor}`\n";
            }
            if ($nextCursor !== null) {
                $output .= "- **Next Cursor:** `{$nextCursor}`\n";
            }
        }

        return $output;
    }

    /**
     * Format a single error for display. The message and error type carry
     * end-user-authored content, so they are neutralized into single-line
     * JSON string literals instead of being interpolated raw.
     *
     * @param  array<string, mixed>  $error
     */
    protected function formatError(int $index, array $error): string
    {
        $id = $error['id'] ?? 'unknown';
        $type = $error['type'] ?? 'php';
        $message = $this->formatUntrustedText((string) ($error['message'] ?? 'No message'));
        $errorType = $this->formatUntrustedText((string) ($error['error_type'] ?? 'Unknown'));
        $environment = $error['environment'] ?? 'unknown';
        $occurrenceCount = $error['occurrence_count'] ?? 1;
        $firstOccurred = $error['first_occurred_at'] ?? 'unknown';
        $lastOccurred = $error['last_occurred_at'] ?? 'unknown';
        $status = $error['status'] ?? 'open';
        $isArchived = ($error['archived'] ?? false) ? 'Yes' : 'No';

        return <<<ERROR
        ---
        ### #{$index} {$id}
        - **Type:** {$type}
        - **Error Type:** {$errorType}
        - **Message:** {$message}
        - **Environment:** {$environment}
        - **Status:** {$status}
        - **Occurrences:** {$occurrenceCount}
        - **First Occurred:** {$firstOccurred}
        - **Last Occurred:** {$lastOccurred}
        - **Archived:** {$isArchived}

        ERROR;
    }
}
