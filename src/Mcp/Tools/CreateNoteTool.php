<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Ranetrace\Laravel\Mcp\Tools\Concerns\FormatsUntrustedText;
use Ranetrace\Laravel\Mcp\Tools\Concerns\MapsErrorActionResponse;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Ranetrace\Laravel\Services\RanetraceApiClient;

class CreateNoteTool extends Tool
{
    use FormatsUntrustedText, MapsErrorActionResponse, NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Create an investigation note on an error. Notes support markdown formatting and can document findings, root causes, and resolution steps.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $this->normalizeErrorId($request->get('error_id'));
        $body = $request->get('body');

        if (empty($errorId)) {
            return Response::error('Error ID is required.');
        }

        if (empty($body)) {
            return Response::error('Note body is required.');
        }

        if (mb_strlen($body) > 5000) {
            return Response::error('Note body exceeds maximum length of 5000 characters.');
        }

        $result = $this->client->createNote($errorId, ['body' => $body]);

        if (! $result['success']) {
            return $this->handleErrorResponse($result, $errorId);
        }

        $note = $result['data']['note'] ?? $result['data'] ?? [];

        if (empty($note)) {
            return Response::error('Failed to create note: empty response received.');
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
            'body' => $schema->string()
                ->description('The markdown content of the note (max 5000 characters).')
                ->required(),
        ];
    }

    protected function actionFailureMessage(): string
    {
        return 'Failed to create note';
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
        # Note Created Successfully

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
