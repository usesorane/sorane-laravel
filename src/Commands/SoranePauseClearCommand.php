<?php

declare(strict_types=1);

namespace Sorane\Laravel\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Sorane\Laravel\Services\SoranePauseManager;

class SoranePauseClearCommand extends Command
{
    protected $signature = 'sorane:pause-clear
                            {--global : Clear global pause}
                            {--feature= : Clear pause for specific feature (errors, events, logs, page_visits, javascript_errors)}
                            {--all : Clear all pauses (global and all features)}';

    protected $description = 'Clear Sorane pause states to resume processing';

    public function handle(SoranePauseManager $pauseManager): int
    {
        $global = $this->option('global');
        $feature = $this->option('feature');
        $all = $this->option('all');

        // Validation: at least one option must be provided
        if (! $global && ! $feature && ! $all) {
            $this->error('You must specify at least one option: --global, --feature, or --all');
            $this->newLine();
            $this->line('Examples:');
            $this->line('  php artisan sorane:pause-clear --global');
            $this->line('  php artisan sorane:pause-clear --feature=errors');
            $this->line('  php artisan sorane:pause-clear --all');

            return self::FAILURE;
        }

        // Clear all pauses
        if ($all) {
            return $this->clearAllPauses($pauseManager);
        }

        // Clear global pause
        if ($global) {
            return $this->clearGlobalPause($pauseManager);
        }

        // Clear feature pause
        if ($feature) {
            return $this->clearFeaturePause($pauseManager, $feature);
        }

        return self::SUCCESS;
    }

    /**
     * Clear all pauses (global and all features).
     */
    protected function clearAllPauses(SoranePauseManager $pauseManager): int
    {
        $this->line('Clearing all pauses...');
        $this->newLine();

        $clearedCount = 0;

        // Clear global
        if ($pauseManager->getGlobalPause()) {
            $this->clearGlobalPause($pauseManager, false);
            $clearedCount++;
        } else {
            $this->line('  Global pause: <fg=gray>Not set</>');
        }

        // Clear all features
        $features = ['errors', 'events', 'logs', 'page_visits', 'javascript_errors'];
        foreach ($features as $feature) {
            if ($pauseManager->getFeaturePause($feature)) {
                $this->clearFeaturePause($pauseManager, $feature, false);
                $clearedCount++;
            } else {
                $this->line("  Feature '{$feature}': <fg=gray>Not paused</>");
            }
        }

        $this->newLine();

        if ($clearedCount === 0) {
            $this->info('No pauses were active.');
        } else {
            $this->info("Successfully cleared {$clearedCount} pause(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Clear global pause.
     */
    protected function clearGlobalPause(SoranePauseManager $pauseManager, bool $showMessages = true): int
    {
        $pauseData = $pauseManager->getGlobalPause();

        if (! $pauseData) {
            if ($showMessages) {
                $this->info('Global pause is not set.');
            }

            return self::SUCCESS;
        }

        if ($showMessages) {
            $this->line('Current global pause:');
            $this->line('  Reason: '.$pauseData['reason']);
            $this->line('  Paused until: '.$pauseData['paused_until']);
            $this->line('  Time remaining: '.$this->formatTimeRemaining($pauseData['paused_until']));
            $this->newLine();
        }

        if ($showMessages && ! $this->confirm('Clear global pause and resume all processing?', true)) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $pauseManager->clearGlobalPause();

        if ($showMessages) {
            $this->info('✓ Global pause cleared successfully.');
            $this->line('  All features will resume processing on next sorane:work execution.');
        } else {
            $this->line('  Global pause: <fg=green>Cleared</>');
        }

        return self::SUCCESS;
    }

    /**
     * Clear feature-specific pause.
     */
    protected function clearFeaturePause(SoranePauseManager $pauseManager, string $feature, bool $showMessages = true): int
    {
        // Validate feature name
        $validFeatures = ['errors', 'events', 'logs', 'page_visits', 'javascript_errors'];
        if (! in_array($feature, $validFeatures, true)) {
            if ($showMessages) {
                $this->error("Invalid feature: {$feature}");
                $this->line('Valid features: '.implode(', ', $validFeatures));
            }

            return self::FAILURE;
        }

        $pauseData = $pauseManager->getFeaturePause($feature);

        if (! $pauseData) {
            if ($showMessages) {
                $this->info("Feature '{$feature}' is not paused.");
            }

            return self::SUCCESS;
        }

        if ($showMessages) {
            $this->line("Current pause for '{$feature}':");
            $this->line('  Reason: '.$pauseData['reason']);
            $this->line('  Paused until: '.$pauseData['paused_until']);
            $this->line('  Time remaining: '.$this->formatTimeRemaining($pauseData['paused_until']));
            $this->newLine();
        }

        if ($showMessages && ! $this->confirm("Clear pause for '{$feature}' and resume processing?", true)) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $pauseManager->clearFeaturePause($feature);

        if ($showMessages) {
            $this->info("✓ Pause cleared for '{$feature}'.");
            $this->line('  This feature will resume processing on next sorane:work execution.');
            $this->newLine();
            $this->warn('Note: If the underlying issue is not resolved, the pause may be set again.');

            // Provide contextual help based on pause reason
            $this->provideContextualHelp($pauseData['reason']);
        } else {
            $this->line("  Feature '{$feature}': <fg=green>Cleared</>");
        }

        return self::SUCCESS;
    }

    /**
     * Provide contextual help based on pause reason.
     */
    protected function provideContextualHelp(string $reason): void
    {
        $this->newLine();
        $this->line('Troubleshooting tips:');

        match ($reason) {
            '401' => $this->line('  • Check that SORANE_KEY in .env is valid and not revoked'),
            '403' => $this->line('  • Verify subscription is active, email is verified, and feature access is enabled'),
            '413' => $this->line('  • Payload too large indicates a CLIENT BUG - investigate batch sizes immediately'),
            '422' => $this->line('  • Validation failure indicates schema drift or malformed data - check recent code changes'),
            '429' => $this->line('  • Rate limit - reduce batch frequency or increase rate limit with API provider'),
            '500' => $this->line('  • Server errors - check backend API health and logs'),
            default => $this->line('  • Check sorane_internal logs for more details'),
        };

        $this->line('  • Run: php artisan sorane:status');
        $this->line('  • Review: storage/logs/sorane-internal.log');
    }

    /**
     * Format time remaining until pause expires.
     */
    protected function formatTimeRemaining(string $pausedUntil): string
    {
        $until = Carbon::parse($pausedUntil);
        $now = Carbon::now();

        if ($until->isPast()) {
            return 'expired (will clear on next check)';
        }

        $diff = $now->diff($until);

        $parts = [];
        if ($diff->h > 0) {
            $parts[] = "{$diff->h}h";
        }
        if ($diff->i > 0) {
            $parts[] = "{$diff->i}m";
        }
        if ($diff->s > 0 || empty($parts)) {
            $parts[] = "{$diff->s}s";
        }

        return implode(' ', $parts);
    }
}
