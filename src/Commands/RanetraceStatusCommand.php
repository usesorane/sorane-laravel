<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Throwable;

class RanetraceStatusCommand extends Command
{
    protected $signature = 'ranetrace:status
                            {--json : Output as JSON instead of formatted text}';

    protected $description = 'Display Ranetrace health status including pauses, buffers, and recent activity';

    public function handle(DashboardData $data): int
    {
        $status = $data->collectStatus();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->displayStatus($status);

        return self::SUCCESS;
    }

    /**
     * Display formatted status output.
     *
     * @param  array<string, mixed>  $status
     */
    protected function displayStatus(array $status): void
    {
        // Header
        $this->newLine();
        $this->line('╔═══════════════════════════════════════════════════════════════╗');
        $this->line('║              RANETRACE HEALTH STATUS                             ║');
        $this->line('╚═══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Overall health
        if ($status['healthy']) {
            $this->info('✓ Overall Status: HEALTHY');
        } else {
            $this->error('✗ Overall Status: ISSUES DETECTED');
        }

        $this->newLine();

        // Configuration
        $this->line('<fg=cyan>CONFIGURATION</>');
        $this->line('─────────────────────────────────────────────────────────────');
        $this->line('Enabled: '.($status['config']['enabled'] ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        $this->line('Ingest API Key: '.($status['config']['api_key_configured'] ? '<fg=green>Configured</>' : '<fg=red>Not Configured</>'));
        $this->line('Cache Driver: '.$status['config']['cache_driver']);
        $this->line('Queue Name: '.$status['config']['queue_name']);
        $this->newLine();

        // Global pause
        $this->line('<fg=cyan>GLOBAL PAUSE STATUS</>');
        $this->line('─────────────────────────────────────────────────────────────');
        if ($status['pauses']['global']) {
            $pause = $status['pauses']['global'];
            if ($pause['paused']) {
                $this->error('✗ PAUSED');
                $this->line('  Reason: '.$pause['reason']);
                $this->line('  Until: '.$pause['paused_until']);
                $this->line('  Remaining: '.$this->formatDuration($pause['time_remaining_seconds']));
            } else {
                $this->warn('○ Pause expired (cleaning up)');
            }
        } else {
            $this->info('✓ Not paused');
        }
        $this->newLine();

        // Feature pauses
        $this->line('<fg=cyan>FEATURE PAUSE STATUS</>');
        $this->line('─────────────────────────────────────────────────────────────');
        foreach ($status['pauses']['features'] as $feature => $pause) {
            if ($pause) {
                if ($pause['paused']) {
                    $this->line(sprintf(
                        '  <fg=red>✗</> %-20s <fg=red>PAUSED</> (reason: %s, remaining: %s)',
                        $feature,
                        $pause['reason'],
                        $this->formatDuration($pause['time_remaining_seconds'])
                    ));
                } else {
                    $this->line(sprintf(
                        '  <fg=yellow>○</> %-20s <fg=yellow>Pause expired</>',
                        $feature
                    ));
                }
            } else {
                $this->line(sprintf(
                    '  <fg=green>✓</> %-20s Active',
                    $feature
                ));
            }
        }
        $this->newLine();

        // Buffers
        $this->line('<fg=cyan>BUFFER STATUS</>');
        $this->line('─────────────────────────────────────────────────────────────');
        $this->line('Total Items: '.$status['buffers']['total']);
        $this->line('Max Per Feature: '.$status['buffers']['max_per_feature']);
        $this->newLine();

        foreach ($status['buffers']['features'] as $feature => $count) {
            $percentage = $status['buffers']['max_per_feature'] > 0
                ? ($count / $status['buffers']['max_per_feature']) * 100
                : 0;

            $bar = $this->createProgressBar($percentage);

            if ($percentage >= 80) {
                $color = 'red';
                $icon = '✗';
            } elseif ($percentage >= 50) {
                $color = 'yellow';
                $icon = '!';
            } else {
                $color = 'green';
                $icon = '✓';
            }

            $this->line(sprintf(
                '  <fg=%s>%s</> %-20s %6d items [%s] %3.0f%%',
                $color,
                $icon,
                $feature,
                $count,
                $bar,
                $percentage
            ));
        }
        $this->newLine();

        // Drain warning — buffers holding items with no recent batch send
        if (! empty($status['drain']['stalled'])) {
            $this->warn('! No recent batch drain for: '.implode(', ', $status['drain']['stalled']));
            $this->line('  Buffered items are not being sent to Ranetrace.');
            $this->line('  Is the <fg=cyan>ranetrace:work</> command scheduled (every minute)?');
            $this->newLine();
        }

        // Failed jobs
        $this->line('<fg=cyan>FAILED JOBS (Last 24h)</>');
        $this->line('─────────────────────────────────────────────────────────────');
        if ($status['failed_jobs_last_24h'] === 0) {
            $this->info('✓ No failed jobs');
        } elseif ($status['failed_jobs_last_24h'] < 10) {
            $this->warn('! '.$status['failed_jobs_last_24h'].' failed job(s) - Review queue:failed');
        } else {
            $this->error('✗ '.$status['failed_jobs_last_24h'].' failed job(s) - INVESTIGATE IMMEDIATELY');
        }
        $this->newLine();

        // Recommendations
        if (! $status['healthy'] || ! empty($status['drain']['stalled'])) {
            $this->line('<fg=cyan>RECOMMENDATIONS</>');
            $this->line('─────────────────────────────────────────────────────────────');

            if (! $status['config']['enabled']) {
                $this->line('• Enable Ranetrace in config/ranetrace.php');
            }

            if (! $status['config']['api_key_configured']) {
                $this->line('• Configure RANETRACE_KEY in .env (the ingest key; the MCP tools use an OAuth connection, held by your MCP client)');
            }

            if ($status['pauses']['global']) {
                $this->line('• Check API credentials (401 indicates invalid/revoked key)');
                $this->line('• Run: php artisan ranetrace:pause-clear --global');
            }

            foreach ($status['pauses']['features'] as $feature => $pause) {
                if ($pause && $pause['paused']) {
                    $reason = $pause['reason'];
                    $this->line("• Feature '{$feature}' paused (reason: {$reason})");

                    if ($reason === '429') {
                        $this->line('  → Rate limit exceeded, wait for auto-resume');
                    } elseif ($reason === '413') {
                        $this->line('  → Batch too large - CLIENT BUG, investigate immediately');
                    } elseif ($reason === '422') {
                        $this->line('  → Validation failed - schema drift or malformed data');
                    } elseif ($reason === '500') {
                        $this->line('  → Server errors - check backend health');
                    } elseif ($reason === '403') {
                        $this->line('  → Access denied - check subscription/permissions');
                    }
                }
            }

            $nearCapacity = array_keys(array_filter(
                $status['buffers']['features'],
                fn (int $count): bool => $count >= $status['buffers']['max_per_feature'] * DashboardData::NEAR_CAPACITY_RATIO
            ));
            if (! empty($nearCapacity)) {
                $this->line('• Buffers approaching capacity: '.implode(', ', $nearCapacity).' - data may be dropped');
                $this->line('• Check if ranetrace:work command is running on schedule');
            }

            if (! empty($status['drain']['stalled'])) {
                $this->line('• No recent drain for: '.implode(', ', $status['drain']['stalled']));
                $this->line('• Ensure ranetrace:work is scheduled every minute (see documentation)');
            }

            if ($status['failed_jobs_last_24h'] >= 10) {
                $this->line('• High failed job count - check ranetrace_internal logs');
                $this->line('• Review failed_jobs table for details');
            }

            $this->newLine();
        }

        $this->line('Last checked: '.$status['timestamp']);
        $this->newLine();

        $this->displayDashboardHint();
    }

    /**
     * Print a one-line pointer to the in-app diagnostics dashboard.
     *
     * Skipped when the dashboard is disabled or its route isn't registered, and
     * defensive against URL-generation failures — a status command must never
     * throw. (Text output only; the --json payload is intentionally untouched.)
     */
    protected function displayDashboardHint(): void
    {
        if (! config('ranetrace.dashboard.enabled', true)) {
            return;
        }

        try {
            if (! Route::has('ranetrace.dashboard')) {
                return;
            }

            $this->line('<fg=cyan>Dashboard:</> '.route('ranetrace.dashboard'));
            $this->newLine();
        } catch (Throwable) {
            // URL generation failed — skip the hint rather than break the command.
        }
    }

    /**
     * Create a simple progress bar.
     */
    protected function createProgressBar(float $percentage): string
    {
        $barWidth = 20;
        $filled = (int) round(($percentage / 100) * $barWidth);
        $empty = $barWidth - $filled;

        return str_repeat('█', $filled).str_repeat('░', $empty);
    }

    /**
     * Format duration in seconds to human-readable format.
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'expired';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes > 0) {
            return "{$minutes}m {$remainingSeconds}s";
        }

        return "{$seconds}s";
    }
}
