<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Ranetrace\Laravel\Mcp\Tools\Concerns\FormatsUntrustedText;
use Ranetrace\Laravel\Mcp\Tools\Concerns\MapsErrorActionResponse;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Ranetrace\Laravel\Services\RanetraceApiClient;

#[IsReadOnly]
class ListNotesTool extends Tool
{
    use FormatsUntrustedText, MapsErrorActionResponse, NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'List investigation notes on an error. Returns notes sorted by newest first with pagination support.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $this->normalizeErrorId($request->get('error_id'));

        if (empty($errorId)) {
            return Response::error('Error ID is required.');
        }

        $params = $this->buildParams($request);
        $result = $this->client->listNotes($errorId, $params);

        if (! $result['success']) {
            return $this->handleErrorResponse($result, $errorId);
        }

        $notes = $result['data']['notes'] ?? [];
        $meta = $result['data']['meta'] ?? [];

        if (empty($notes)) {
            return Response::text("No notes found for error '{$errorId}'.");
        }

        return Response::text($this->formatNotesList($notes, $meta, $errorId));
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
            'limit' => $schema->integer()
                ->description('Maximum number of notes to return (default: 50, max: 100).')
                ->min(1)
                ->max(100),
            'author' => $schema->string()
                ->description('Filter by author ("ai" for AI Agent notes, or user ID).'),
        ];
    }

    /**
     * Build request parameters from the request.
     *
     * @return array<string, mixed>
     */
    protected function buildParams(Request $request): array
    {
        $params = [];

        $limit = $request->get('limit');
        if ($limit !== null) {
            $params['limit'] = min(max((int) $limit, 1), 100);
        }

        $author = $request->get('author');
        if ($author !== null && $author !== '') {
            $params['author'] = $author;
        }

        return $params;
    }

    protected function actionFailureMessage(): string
    {
        return 'Failed to list notes';
    }

    /**
     * Format the notes list for display.
     *
     * @param  array<int, array<string, mixed>>  $notes
     * @param  array<string, mixed>  $meta
     */
    protected function formatNotesList(array $notes, array $meta, string $errorId): string
    {
        $total = $meta['total'] ?? count($notes);

        $output = "# Notes for Error {$errorId}\n\n";
        $output .= 'Showing '.count($notes)." of {$total} notes\n\n";
        $output .= $this->untrustedTextNotice()."\n\n";

        foreach ($notes as $note) {
            $id = $note['id'] ?? 'unknown';
            $authorName = $this->formatUntrustedText((string) ($note['author_name'] ?? 'Unknown'));
            $createdAt = $note['created_at'] ?? 'unknown';
            $body = (string) ($note['body'] ?? '');
            $archived = ! empty($note['archived']) ? ' [ARCHIVED]' : '';

            $truncatedBody = mb_strlen($body) > 200
                ? mb_substr($body, 0, 200).'...'
                : $body;

            $output .= "---\n\n";
            $output .= "**Note ID:** {$id}{$archived}\n";
            $output .= "**Author:** {$authorName}\n";
            $output .= "**Created:** {$createdAt}\n\n";
            $output .= $this->formatUntrustedBlock($truncatedBody)."\n\n";
        }

        return $output;
    }
}
