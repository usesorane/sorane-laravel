<?php

declare(strict_types=1);

namespace Sorane\Laravel\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sorane\Laravel\Services\SoraneBatchBuffer;
use Sorane\Laravel\Services\SoranePauseManager;
use Throwable;

class SoraneStatusCommand extends Command
{
    protected $signature = 'sorane:status
                            {--json : Output as JSON instead of formatted text}';

    protected $description = 'Display Sorane health status including pauses, buffers, and recent activity';

    public function handle(SoraneBatchBuffer $buffer, SoranePauseManager $pauseManager): int
    {
        $status = $this->collectStatus($buffer, $pauseManager);

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->displayStatus($status);

        return self::SUCCESS;
    }

    /**
     * Collect all status information.
     *
     * @return array<string, mixed>
     */
    protected function collectStatus(SoraneBatchBuffer $buffer, SoranePauseManager $pauseManager): array
    {
        $features = ['errors', 'events', 'logs', 'page_visits', 'javascript_errors'];

        // Check global pause
        $globalPause = $pauseManager->getGlobalPause();
        $isGloballyPaused = $pauseManager->isGloballyPaused();

        // Check feature pauses
        $featurePauses = [];
        foreach ($features as $feature) {
            $pauseData = $pauseManager->getFeaturePause($feature);
            $featurePauses[$feature] = $pauseData ? [
                'paused' => $pauseManager->isFeaturePaused($feature),
                'paused_until' => $pauseData['paused_until'],
                'reason' => $pauseData['reason'],
                'time_remaining_seconds' => max(0, Carbon::parse($pauseData['paused_until'])->diffInSeconds(Carbon::now(), false)),
            ] : null;
        }

        // Get buffer sizes
        $buffers = [];
        $totalBuffered = 0;
        foreach ($features as $feature) {
            $count = $buffer->count($feature);
            $buffers[$feature] = $count;
            $totalBuffered += $count;
        }

        // Get failed jobs count (last 24 hours)
        $failedJobsCount = $this->getFailedJobsCount();

        // Overall health determination
        $healthy = ! $isGloballyPaused
            && $totalBuffered < config('sorane.batch.max_buffer_size', 5000) * 0.8 // Not approaching max
            && $failedJobsCount < 10; // Fewer than 10 failed jobs

        return [
            'healthy' => $healthy,
            'timestamp' => Carbon::now()->toIso8601String(),
            'pauses' => [
                'global' => $globalPause ? [
                    'paused' => $isGloballyPaused,
                    'paused_until' => $globalPause['paused_until'],
                    'reason' => $globalPause['reason'],
                    'time_remaining_seconds' => max(0, Carbon::parse($globalPause['paused_until'])->diffInSeconds(Carbon::now(), false)),
                ] : null,
                'features' => $featurePauses,
            ],
            'buffers' => [
                'total' => $totalBuffered,
                'max_per_feature' => config('sorane.batch.max_buffer_size', 5000),
                'features' => $buffers,
            ],
            'failed_jobs_last_24h' => $failedJobsCount,
            'config' => [
                'enabled' => config('sorane.enabled', false),
                'api_key_configured' => ! empty(config('sorane.key')),
                'cache_driver' => config('sorane.batch.cache_driver', 'redis'),
                'queue_name' => config('sorane.batch.queue_name', 'default'),
            ],
        ];
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
        $this->line('║              SORANE HEALTH STATUS                             ║');
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
        $this->line('API Key: '.($status['config']['api_key_configured'] ? '<fg=green>Configured</>' : '<fg=red>Not Configured</>'));
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
        $pausedCount = 0;
        foreach ($status['pauses']['features'] as $feature => $pause) {
            if ($pause) {
                $pausedCount++;
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
        if (! $status['healthy']) {
            $this->line('<fg=cyan>RECOMMENDATIONS</>');
            $this->line('─────────────────────────────────────────────────────────────');

            if (! $status['config']['enabled']) {
                $this->line('• Enable Sorane in config/sorane.php');
            }

            if (! $status['config']['api_key_configured']) {
                $this->line('• Configure SORANE_KEY in .env');
            }

            if ($status['pauses']['global']) {
                $this->line('• Check API credentials (401 indicates invalid/revoked key)');
                $this->line('• Run: php artisan sorane:pause-clear --global');
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

            if ($status['buffers']['total'] > $status['buffers']['max_per_feature'] * 0.8) {
                $this->line('• Buffers approaching capacity - data may be dropped');
                $this->line('• Check if sorane:work command is running on schedule');
            }

            if ($status['failed_jobs_last_24h'] >= 10) {
                $this->line('• High failed job count - check sorane_internal logs');
                $this->line('• Review failed_jobs table for details');
            }

            $this->newLine();
        }

        $this->line('Last checked: '.$status['timestamp']);
        $this->newLine();
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

    /**
     * Get count of failed Sorane jobs in the last 24 hours.
     */
    protected function getFailedJobsCount(): int
    {
        try {
            return DB::table('failed_jobs')
                ->where('payload', 'like', '%Sorane%')
                ->where('failed_at', '>=', Carbon::now()->subDay())
                ->count();
        } catch (Throwable) {
            // Table might not exist or database not available
            return 0;
        }
    }
}
