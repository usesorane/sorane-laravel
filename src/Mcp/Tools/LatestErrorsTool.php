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
class LatestErrorsTool extends Tool
{
    use FormatsUntrustedText;

    protected const VALID_STATUSES = ['open', 'resolved', 'ignored', 'snoozed', 'active', 'closed', 'all'];

    /**
     * The tool's description.
     */
    protected string $description = 'Fetch the latest errors from Ranetrace. By default, only returns open errors (not resolved/ignored). Use status parameter to filter by different states or "all" to include all errors.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $params = array_filter([
            'limit' => $request->get('limit'),
            'environment' => $request->get('environment'),
            'type' => $request->get('type'),
        ], fn ($value) => $value !== null);

        // Default to 'open' status if not specified, skip if 'all'
        $status = $request->get('status') ?? 'open';
        if ($status !== 'all') {
            $params['status'] = $status;
        }

        $result = $this->client->getLatestErrors($params);

        if (! $result['success']) {
            $errorMessage = $result['error'] ?? 'Unknown error occurred';

            return Response::error("Failed to fetch errors: {$errorMessage}");
        }

        $errors = $result['data']['errors'] ?? [];

        if (empty($errors)) {
            return Response::text('No errors found matching the specified criteria.');
        }

        $output = 'Found '.count($errors)." error(s):\n\n";
        $output .= $this->untrustedTextNotice()."\n\n";

        foreach ($errors as $index => $error) {
            $output .= $this->formatError($index + 1, $error);
        }

        return Response::text($output);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of errors to return (1-100). Defaults to 10.')
                ->default(10),

            'environment' => $schema->string()
                ->description('Filter by environment (e.g., "production", "staging", "local").'),

            'type' => $schema->string()
                ->description('Filter by error type (e.g., "exception", "javascript").'),

            'status' => $schema->string()
                ->description('Status filter: "open" (default), "resolved", "ignored", "snoozed", "active", "closed", or "all" to include all statuses.')
                ->enum(self::VALID_STATUSES)
                ->default('open'),
        ];
    }

    /**
     * Format a single error for display. The message carries end-user-authored
     * content, so it is neutralized into a single-line JSON string literal
     * instead of being interpolated raw.
     *
     * @param  array<string, mixed>  $error
     */
    protected function formatError(int $index, array $error): string
    {
        $id = $error['id'] ?? 'unknown';
        $message = $this->formatUntrustedText((string) ($error['message'] ?? 'No message'));
        $type = $error['type'] ?? 'unknown';
        $environment = $error['environment'] ?? 'unknown';
        $occurredAt = $error['occurred_at'] ?? 'unknown';
        $occurrences = $error['occurrences'] ?? 1;
        $status = $error['status'] ?? 'unknown';

        return <<<ERROR
        ---
        #{$index} Error ID: {$id}
        Type: {$type}
        Environment: {$environment}
        Status: {$status}
        Message: {$message}
        Occurred at: {$occurredAt}
        Occurrences: {$occurrences}

        ERROR;
    }
}
