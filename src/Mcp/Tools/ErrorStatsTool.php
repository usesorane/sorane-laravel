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
class ErrorStatsTool extends Tool
{
    use FormatsUntrustedText;

    /**
     * The tool's description.
     */
    protected string $description = 'Get error statistics from Ranetrace for a specified time period. Returns counts, trends, and breakdowns by type and environment.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $params = array_filter([
            'period' => $request->get('period'),
        ], fn ($value) => $value !== null);

        $result = $this->client->getErrorStats($params);

        if (! $result['success']) {
            $errorMessage = $result['error'] ?? 'Unknown error occurred';

            return Response::error("Failed to fetch error statistics: {$errorMessage}");
        }

        $stats = $result['data']['stats'] ?? $result['data'] ?? [];

        if (empty($stats)) {
            return Response::text('No error statistics available for the specified period.');
        }

        return Response::text($this->formatStats($stats, $request->get('period', '24h')));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()
                ->enum(['1h', '24h', '7d', '30d'])
                ->description('The time period for statistics. Options: 1h (last hour), 24h (last 24 hours), 7d (last 7 days), 30d (last 30 days).')
                ->default('24h'),
        ];
    }

    /**
     * Format the statistics for display.
     *
     * @param  array<string, mixed>  $stats
     */
    protected function formatStats(array $stats, string $period): string
    {
        $periodLabels = [
            '1h' => 'Last Hour',
            '24h' => 'Last 24 Hours',
            '7d' => 'Last 7 Days',
            '30d' => 'Last 30 Days',
        ];

        $periodLabel = $periodLabels[$period] ?? $period;

        $totalErrors = $stats['total_errors'] ?? 0;
        $uniqueErrors = $stats['unique_errors'] ?? 0;
        $resolvedErrors = $stats['resolved_errors'] ?? 0;

        $output = <<<STATS
        # Error Statistics - {$periodLabel}

        **Total Errors:** {$totalErrors}
        **Unique Errors:** {$uniqueErrors}
        **Resolved Errors:** {$resolvedErrors}

        STATS;

        if (! empty($stats['by_type'])) {
            $output .= "\n## By Type\n";
            foreach ($stats['by_type'] as $type => $count) {
                $output .= "- {$type}: {$count}\n";
            }
        }

        if (! empty($stats['by_environment'])) {
            $output .= "\n## By Environment\n";
            foreach ($stats['by_environment'] as $env => $count) {
                $output .= "- {$env}: {$count}\n";
            }
        }

        if (! empty($stats['trend'])) {
            $trend = $stats['trend'];
            $trendDirection = $trend['direction'] ?? 'stable';
            $trendPercentage = $trend['percentage'] ?? 0;

            $trendIcon = match ($trendDirection) {
                'up' => '↑',
                'down' => '↓',
                default => '→',
            };

            $output .= "\n## Trend\n";
            $output .= "{$trendIcon} {$trendDirection} ({$trendPercentage}% compared to previous period)\n";
        }

        if (! empty($stats['top_errors'])) {
            $output .= "\n## Top Errors\n";
            $output .= $this->untrustedTextNotice()."\n\n";

            foreach ($stats['top_errors'] as $index => $error) {
                // End-user-authored, same as every other rendered error message.
                $errorMessage = $this->formatUntrustedText((string) ($error['message'] ?? 'Unknown'));
                $errorCount = $error['count'] ?? 0;
                $num = $index + 1;
                $output .= "{$num}. {$errorMessage} ({$errorCount} occurrences)\n";
            }
        }

        return $output;
    }
}
