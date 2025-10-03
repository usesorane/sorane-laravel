<?php

declare(strict_types=1);

namespace Sorane\Laravel\Commands;

use Illuminate\Console\Command;

class SoraneAnalyticsTestCommand extends Command
{
    protected $signature = 'sorane:test-analytics';

    protected $description = 'Display website analytics configuration and usage instructions';

    public function handle(): int
    {
        $this->info('🔍 Sorane Website Analytics Test');
        $this->newLine();

        // Display current configuration
        $this->line('📋 <fg=cyan>Current Configuration:</>');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', config('sorane.website_analytics.enabled') ? '✅ Yes' : '❌ No'],
                ['Queue Enabled', config('sorane.website_analytics.queue') ? '✅ Yes' : '❌ No'],
                ['Queue Name', config('sorane.website_analytics.queue_name', 'default')],
                ['Timeout', config('sorane.website_analytics.timeout', 10).' seconds'],
                ['Throttle', config('sorane.website_analytics.throttle_seconds', 30).' seconds'],
                ['Batch Size', config('sorane.website_analytics.batch.size', 100)],
                ['User Agent Min Length', config('sorane.website_analytics.user_agent.min_length', 10)],
                ['User Agent Max Length', config('sorane.website_analytics.user_agent.max_length', 1000)],
                ['Excluded Paths', count(config('sorane.website_analytics.excluded_paths', [])).' path(s)'],
            ]
        );

        $this->newLine();

        if (! config('sorane.website_analytics.enabled')) {
            $this->warn('⚠️  Website analytics is currently disabled.');
            $this->info('💡 To enable it, add to your .env file:');
            $this->line('   SORANE_WEBSITE_ANALYTICS_ENABLED=true');
            $this->newLine();

            return self::SUCCESS;
        }

        if (empty(config('sorane.key'))) {
            $this->error('❌ Sorane API key is not set!');
            $this->info('💡 Add your API key to .env:');
            $this->line('   SORANE_KEY=your-api-key-here');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('✅ Website analytics is enabled and configured!');
        $this->newLine();

        // How it works
        $this->line('🚀 <fg=cyan>How It Works:</>');
        $this->newLine();
        $this->line('Analytics are automatically tracked via middleware when enabled.');
        $this->line('The <fg=yellow>TrackPageVisit</> middleware is added to the <fg=cyan>web</> middleware group.');
        $this->newLine();

        // What gets tracked
        $this->line('📊 <fg=cyan>What Gets Tracked:</>');
        $this->table(
            ['Data Point', 'Description'],
            [
                ['URL & Path', 'Full URL and path of the visited page'],
                ['Timestamp', 'When the visit occurred (ISO 8601)'],
                ['Referrer', 'Where the visitor came from'],
                ['Device Type', 'mobile, tablet, desktop, or console'],
                ['Browser', 'Chrome, Firefox, Safari, Edge, etc.'],
                ['UTM Parameters', 'source, medium, campaign, content, term'],
                ['Session ID (hashed)', 'SHA-256 hash for privacy'],
                ['User Agent (hashed)', 'SHA-256 hash for privacy'],
                ['Human Probability', 'Bot detection score (0.0 - 1.0)'],
            ]
        );

        $this->newLine();

        // Bot detection
        $this->line('🤖 <fg=cyan>Bot Detection & Filtering:</>');
        $this->table(
            ['Detection Method', 'Description'],
            [
                ['User Agent Length', 'Filters too short (<10) or too long (>1000) UAs'],
                ['Suspicious Patterns', 'Filters "test", "curl", "wget", "bot", etc.'],
                ['CrawlerDetect Library', 'Detects 40+ known bots and crawlers'],
                ['Extra Bot List', 'GoogleBot, ChatGPT, ClaudeBot, Puppeteer, etc.'],
                ['Header Validation', 'Requires Accept-Language, validates Accept header'],
                ['Human Probability', 'Requests scored as "bot" are excluded'],
            ]
        );

        $this->newLine();

        // Excluded paths
        $excludedPaths = config('sorane.website_analytics.excluded_paths', []);
        if (! empty($excludedPaths)) {
            $this->line('🚫 <fg=cyan>Excluded Paths (visits to these are NOT tracked):</>');
            foreach ($excludedPaths as $path) {
                $this->line('   • /'.$path.'/*');
            }
            $this->newLine();
        }

        // Testing
        $this->line('🧪 <fg=cyan>Testing Analytics:</>');
        $this->newLine();
        $this->line('1. Visit your website with a regular browser');
        $this->line('2. Navigate through different pages');
        $this->line('3. Check your Sorane dashboard to see the visits');
        $this->newLine();

        $this->line('<fg=yellow>Note:</> Analytics are tracked automatically, no code changes needed!');
        $this->newLine();

        // Privacy
        $this->line('🔒 <fg=cyan>Privacy Features:</>');
        $this->table(
            ['Item', 'How It\'s Handled'],
            [
                ['IP Address', 'NOT sent to Sorane (privacy-first)'],
                ['User Agent', 'Hashed with SHA-256 (not stored raw)'],
                ['Session ID', 'Generated from IP + UA + Date (daily rotation, hashed)'],
                ['Personal Data', 'No personal information is collected'],
            ]
        );

        $this->newLine();

        // Configuration options
        $this->line('⚙️  <fg=cyan>Configuration Options:</>');
        $this->newLine();
        $this->line('Add to <fg=yellow>.env</> file:');
        $this->line('');
        $this->line('<fg=yellow># Enable/disable analytics');
        $this->line('SORANE_WEBSITE_ANALYTICS_ENABLED=true');
        $this->line('');
        $this->line('# Queue visits (recommended for performance)');
        $this->line('SORANE_WEBSITE_ANALYTICS_QUEUE=true');
        $this->line('');
        $this->line('# Throttle duplicate visits (seconds)');
        $this->line('SORANE_WEBSITE_ANALYTICS_THROTTLE_SECONDS=30');
        $this->line('');
        $this->line('# User agent validation');
        $this->line('SORANE_WEBSITE_ANALYTICS_UA_MIN_LENGTH=10');
        $this->line('SORANE_WEBSITE_ANALYTICS_UA_MAX_LENGTH=1000</>');
        $this->newLine();

        $this->line('Add to <fg=yellow>config/sorane.php</>:');
        $this->line('');
        $this->line("<fg=yellow>'website_analytics' => [");
        $this->line("    'excluded_paths' => [");
        $this->line("        'admin',      // Don't track /admin/*");
        $this->line("        'api',        // Don't track /api/*");
        $this->line("        'telescope',  // Don't track /telescope/*");
        $this->line('    ],');
        $this->line(']</>');

        return self::SUCCESS;
    }
}
