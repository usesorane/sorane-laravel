<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Ranetrace\Laravel\Mcp\Tools\Concerns\FormatsUntrustedText;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Ranetrace\Laravel\Services\RanetraceApiClient;

class UpdateNoteTool extends Tool
{
    use FormatsUntrustedText;
    use NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Update an investigation note on an error. Can only update notes created by the AI Agent.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $this->normalizeErrorId($request->get('error_id'));
        $noteId = $this->normalizeNoteId($request->get('note_id'));
        $body = $request->get('body');

        if (empty($errorId)) {
            return Response::error('Error ID is required.');
        }

        if (empty($noteId)) {
            return Response::error('Note ID is required.');
        }

        if (empty($body)) {
            return Response::error('Note body is required.');
        }

        if (mb_strlen($body) > 5000) {
            return Response::error('Note body exceeds maximum length of 5000 characters.');
        }

        $result = $this->client->updateNote($errorId, $noteId, ['body' => $body]);

        if (! $result['success']) {
            return $this->handleErrorResponse($result, $errorId, $noteId);
        }

        $note = $result['data']['note'] ?? $result['data'] ?? [];

        if (empty($note)) {
            return Response::error('Failed to update note: empty response received.');
        }

        return Response::text($this->formatNoteDetails($note, $errorId));
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
                ->description('The error ID (with or without err_ prefix).')
                ->required(),
            'note_id' => $schema->string()
                ->description('The note ID (with or without note_ prefix).')
                ->required(),
            'body' => $schema->string()
                ->description('The new markdown content of the note (max 5000 characters).')
                ->required(),
        ];
    }

    /**
     * Handle error responses from the API.
     *
     * @param  array<string, mixed>  $result
     */
    protected function handleErrorResponse(array $result, string $errorId, string $noteId): Response
    {
        $errorMessage = $result['error'] ?? 'Unknown error occurred';

        if ($result['status'] === 404) {
            return str_contains(mb_strtolower($errorMessage), 'error')
                ? Response::error("Error with ID '{$errorId}' not found.")
                : Response::error("Note with ID '{$noteId}' not found.");
        }

        return match ($result['status']) {
            403 => Response::error('Access denied: Cannot modify notes created by other users.'),
            422 => Response::error("Validation failed: {$errorMessage}"),
            default => Response::error("Failed to update note: {$errorMessage}"),
        };
    }

    /**
     * Format the note details for display.
     *
     * @param  array<string, mixed>  $note
     */
    protected function formatNoteDetails(array $note, string $errorId): string
    {
        $id = $note['id'] ?? 'unknown';
        $noteErrorId = $note['error_id'] ?? $errorId;
        $body = $this->formatUntrustedBlock((string) ($note['body'] ?? ''));
        $authorName = $this->formatUntrustedText((string) ($note['author_name'] ?? 'Unknown'));
        $createdAt = $note['created_at'] ?? 'unknown';
        $updatedAt = $note['updated_at'] ?? $createdAt;
        $notice = $this->untrustedTextNotice();

        return <<<NOTE
        # Note Updated Successfully

        {$notice}

        **Note ID:** {$id}
        **Error ID:** {$noteErrorId}
        **Author:** {$authorName}
        **Created at:** {$createdAt}
        **Updated at:** {$updatedAt}

        ## Content

        {$body}
        NOTE;
    }
}
