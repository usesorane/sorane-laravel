<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Support\Core;

class RanetraceAnalyticsTestCommand extends Command
{
    protected $signature = 'ranetrace:test-analytics';

    protected $description = 'Display website analytics configuration and usage instructions';

    public function handle(): int
    {
        $this->info('🔍 Ranetrace Website Analytics Test');
        $this->newLine();

        // Display current configuration
        $this->line('📋 <fg=cyan>Current Configuration:</>');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', config('ranetrace.website_analytics.enabled') ? '✅ Yes' : '❌ No'],
                ['Queue Enabled', config('ranetrace.website_analytics.queue') ? '✅ Yes' : '❌ No'],
                ['Queue Name', config('ranetrace.website_analytics.queue_name', 'default')],
                ['Timeout', config('ranetrace.website_analytics.timeout', 10).' seconds'],
                ['Throttle', config('ranetrace.website_analytics.throttle_seconds', 30).' seconds'],
                ['User Agent Min Length', config('ranetrace.website_analytics.user_agent.min_length', 10)],
                ['User Agent Max Length', config('ranetrace.website_analytics.user_agent.max_length', 1000)],
                ['Excluded Paths', count(config('ranetrace.website_analytics.excluded_paths', [])).' path(s)'],
            ]
        );

        $this->newLine();

        if (! config('ranetrace.website_analytics.enabled')) {
            $this->warn('⚠️  Website analytics is currently disabled.');
            $this->info('💡 To enable it, add to your .env file:');
            $this->line('   RANETRACE_WEBSITE_ANALYTICS_ENABLED=true');
            $this->newLine();

            return self::SUCCESS;
        }

        if (empty(config('ranetrace.key'))) {
            $this->error('❌ Ranetrace API key is not set!');
            $this->info('💡 Add your API key to .env:');
            $this->line('   RANETRACE_KEY=your-api-key-here');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('✅ Website analytics is enabled and configured!');
        $this->newLine();

        // Dispatch a synthetic page visit so the user can verify the
        // job → buffer → API path without a browser, mirroring the other
        // test commands (test-errors/events/logging/javascript-errors).
        HandlePageVisitJob::dispatch([
            'url' => 'cli://ranetrace:test-analytics',
            'path' => '/ranetrace-test-analytics',
            'timestamp' => now()->toIso8601String(),
            'referrer' => null,
            'country_code' => null,
            'device_type' => 'desktop',
            'browser_name' => 'Other',
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_content' => null,
            'utm_term' => null,
            'session_id_hash' => Core::fingerprints()->hash('ranetrace:test-analytics'),
            'user_agent_hash' => Core::fingerprints()->hash('Ranetrace-CLI/Test'),
            'human_probability_score' => 100,
            'human_probability_reasons' => ['cli-test'],
        ]);

        if (config('ranetrace.website_analytics.queue', true)) {
            $this->info('✅ Test page visit queued for Ranetrace. Run php artisan ranetrace:work to send it.');
        } else {
            $this->info('✅ Test page visit sent to Ranetrace.');
        }
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
                ['URL & Path', 'Full URL (sensitive query params redacted) and path'],
                ['Timestamp', 'When the visit occurred (ISO 8601)'],
                ['Referrer', 'Where the visitor came from'],
                ['Device Type', 'mobile, tablet, desktop, or console'],
                ['Browser', 'Chrome, Firefox, Safari, Edge, etc.'],
                ['UTM Parameters', 'source, medium, campaign, content, term'],
                ['Session ID (hashed)', 'HMAC-SHA256 (salted) for privacy'],
                ['User Agent (hashed)', 'HMAC-SHA256 (salted) for privacy'],
                ['Human Probability', 'Bot detection score (0-100, integer)'],
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
        $excludedPaths = config('ranetrace.website_analytics.excluded_paths', []);
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
        $this->line('3. Check your Ranetrace dashboard to see the visits');
        $this->newLine();

        $this->line('<fg=yellow>Note:</> Analytics are tracked automatically, no code changes needed!');
        $this->newLine();

        // Privacy
        $this->line('🔒 <fg=cyan>Privacy Features:</>');
        $this->table(
            ['Item', 'How It\'s Handled'],
            [
                ['IP Address', 'NOT sent to Ranetrace (privacy-first)'],
                ['User Agent', 'Hashed with HMAC-SHA256, salted (not stored raw)'],
                ['Session ID', 'HMAC-SHA256 of IP + UA + Date (daily rotation, salted)'],
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
        $this->line('RANETRACE_WEBSITE_ANALYTICS_ENABLED=true');
        $this->line('');
        $this->line('# Queue visits (recommended for performance)');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_QUEUE=true');
        $this->line('');
        $this->line('# Throttle duplicate visits (seconds)');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_THROTTLE_SECONDS=30');
        $this->line('');
        $this->line('# User agent validation');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_UA_MIN_LENGTH=10');
        $this->line('RANETRACE_WEBSITE_ANALYTICS_UA_MAX_LENGTH=1000</>');
        $this->newLine();

        $this->line('Add to <fg=yellow>config/ranetrace.php</>:');
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
