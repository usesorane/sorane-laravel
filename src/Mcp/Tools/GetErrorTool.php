<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ranetrace\Laravel\Mcp\Tools\Concerns\FormatsUntrustedText;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Ranetrace\Laravel\Services\RanetraceApiClient;

#[IsReadOnly]
class GetErrorTool extends Tool
{
    use FormatsUntrustedText;
    use NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Get detailed information about a specific error by its ID. Returns the full error details including stack trace, context, and metadata.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $request->get('error_id');

        if ($errorId === null || $errorId === '') {
            return Response::error('Error ID is required.');
        }

        // get-error queries the PHP error store only (the backend GET endpoint is
        // PHP-only and strips an err_ prefix itself). A jserr_ (JavaScript) id
        // would otherwise collide with a same-numbered PHP error and silently
        // return the wrong one, so reject it with a clear pointer instead.
        if ($this->errorTypeFromPrefix($errorId) === 'javascript') {
            return Response::error('get-error retrieves PHP errors only. For a JavaScript error, use search-errors (type: "javascript") or get-error-activity.');
        }

        $result = $this->client->getError($errorId);

        if (! $result['success']) {
            $errorMessage = $result['error'] ?? 'Unknown error occurred';

            if ($result['status'] === 404) {
                return Response::error("Error with ID '{$errorId}' not found.");
            }

            return Response::error("Failed to fetch error: {$errorMessage}");
        }

        $error = $result['data']['error'] ?? $result['data'] ?? [];

        if (empty($error)) {
            return Response::error("Error with ID '{$errorId}' not found.");
        }

        return Response::text($this->formatErrorDetails($error));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'error_id' => $schema->string()
                ->description('The PHP error ID (with or without err_ prefix). JavaScript (jserr_) errors are not available here.')
                ->required(),
        ];
    }

    /**
     * Format the full error details for display.
     *
     * The message, exception class and the stack trace, context, request and
     * user blocks all carry end-user-authored content, so they are neutralized
     * rather than interpolated raw into what the calling agent reads.
     *
     * @param  array<string, mixed>  $error
     */
    protected function formatErrorDetails(array $error): string
    {
        $id = $error['id'] ?? 'unknown';
        $message = $this->formatUntrustedText((string) ($error['message'] ?? 'No message'));
        $type = $error['type'] ?? 'unknown';
        $exceptionClass = $this->formatUntrustedText((string) ($error['exception_class'] ?? 'unknown'));
        $environment = $error['environment'] ?? 'unknown';
        $occurredAt = $error['occurred_at'] ?? 'unknown';
        $occurrences = $error['occurrences'] ?? 1;
        $file = $error['file'] ?? 'unknown';
        $line = $error['line'] ?? 'unknown';

        $notice = $this->untrustedTextNotice();

        $output = <<<ERROR
        # Error Details

        {$notice}

        **ID:** {$id}
        **Type:** {$type}
        **Exception Class:** {$exceptionClass}
        **Environment:** {$environment}
        **Message:** {$message}
        **File:** {$file}:{$line}
        **Occurred at:** {$occurredAt}
        **Total Occurrences:** {$occurrences}

        ERROR;

        if (! empty($error['stack_trace'])) {
            $stackTrace = is_array($error['stack_trace'])
                ? implode("\n", $error['stack_trace'])
                : $error['stack_trace'];
            $output .= "\n## Stack Trace\n".$this->formatUntrustedBlock((string) $stackTrace)."\n";
        }

        if (! empty($error['context'])) {
            $context = is_array($error['context'])
                ? json_encode($error['context'], JSON_PRETTY_PRINT)
                : $error['context'];
            $output .= "\n## Context\n".$this->formatUntrustedBlock((string) $context, 'json')."\n";
        }

        if (! empty($error['request'])) {
            $requestData = is_array($error['request'])
                ? json_encode($error['request'], JSON_PRETTY_PRINT)
                : $error['request'];
            $output .= "\n## Request Data\n".$this->formatUntrustedBlock((string) $requestData, 'json')."\n";
        }

        if (! empty($error['user'])) {
            $userData = is_array($error['user'])
                ? json_encode($error['user'], JSON_PRETTY_PRINT)
                : $error['user'];
            $output .= "\n## User\n".$this->formatUntrustedBlock((string) $userData, 'json')."\n";
        }

        return $output;
    }
}
