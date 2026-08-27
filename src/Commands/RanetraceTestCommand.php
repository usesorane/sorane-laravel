<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;

class RanetraceTestCommand extends Command
{
    public $signature = 'ranetrace:test
                        {--feature= : Test specific feature(s) - comma-separated or use "all"}
                        {--all : Test all features}';

    public $description = 'Validate Ranetrace configuration and optionally test specific features';

    public function handle(): int
    {
        $feature = $this->option('feature');
        $all = $this->option('all');

        // If --all flag or feature=all, test everything
        if ($all || $feature === 'all') {
            return $this->testAllFeatures();
        }

        // If specific feature(s) requested
        if ($feature) {
            // Support comma-separated features
            if (str_contains($feature, ',')) {
                return $this->testMultipleFeatures(explode(',', $feature));
            }

            return $this->testSpecificFeature($feature);
        }

        // Otherwise, run the general configuration validation
        return $this->validateConfiguration();
    }

    protected function testAllFeatures(): int
    {
        $this->info('🧪 Testing All Ranetrace Features...');
        $this->newLine();

        $features = ['errors', 'events', 'logging', 'javascript_errors', 'analytics'];
        $results = [];

        foreach ($features as $feature) {
            $this->line("Running test for: <fg=cyan>{$feature}</>");
            $exitCode = $this->testSpecificFeature($feature, false);
            $results[$feature] = $exitCode === self::SUCCESS;
            $this->newLine();
        }

        // Summary
        $this->info('📊 Test Summary:');
        $this->table(
            ['Feature', 'Result'],
            collect($results)->map(function ($passed, $feature) {
                return [
                    ucfirst(str_replace('_', ' ', $feature)),
                    $passed ? '<fg=green>✓ Passed</>' : '<fg=red>✗ Failed</>',
                ];
            })->values()->toArray()
        );

        $allPassed = collect($results)->every(fn ($passed) => $passed);

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    protected function testMultipleFeatures(array $features): int
    {
        $features = array_map('trim', $features);

        $this->info('🧪 Testing Multiple Features: '.implode(', ', $features));
        $this->newLine();

        $results = [];

        foreach ($features as $feature) {
            $exitCode = $this->testSpecificFeature($feature, false);
            $results[$feature] = $exitCode === self::SUCCESS;
            $this->newLine();
        }

        // Summary
        $this->info('📊 Test Summary:');
        $this->table(
            ['Feature', 'Result'],
            collect($results)->map(function ($passed, $feature) {
                return [
                    ucfirst(str_replace('_', ' ', $feature)),
                    $passed ? '<fg=green>✓ Passed</>' : '<fg=red>✗ Failed</>',
                ];
            })->values()->toArray()
        );

        $allPassed = collect($results)->every(fn ($passed) => $passed);

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    protected function testSpecificFeature(string $feature, bool $showHeader = true): int
    {
        $featureCommands = [
            'errors' => 'ranetrace:test-errors',
            'events' => 'ranetrace:test-events',
            'logging' => 'ranetrace:test-logging',
            'javascript_errors' => 'ranetrace:test-javascript-errors',
            'analytics' => 'ranetrace:test-analytics',
        ];

        if (! isset($featureCommands[$feature])) {
            $this->error("❌ Unknown feature: {$feature}");
            $this->info('💡 Available features: '.implode(', ', array_keys($featureCommands)));

            return self::FAILURE;
        }

        $command = $featureCommands[$feature];

        if ($showHeader) {
            $this->info("Running feature test: {$feature}");
            $this->newLine();
        }

        return $this->call($command);
    }

    protected function validateConfiguration(): int
    {
        $this->info('🔍 Testing Ranetrace Configuration...');
        $this->newLine();

        $config = config('ranetrace');

        // Test the structure of the config file
        if (! is_array($config)) {
            $this->error('❌ Ranetrace configuration file is not valid.');

            return self::FAILURE;
        }

        // Check the ingest key. This is the credential that sends captured data
        // to Ranetrace, and every feature validated below depends on it, so a
        // missing one fails the command.
        if (empty($config['key'])) {
            $this->error('❌ Ranetrace ingest API key is not set.');
            $this->info('💡 Add to your .env file: RANETRACE_KEY=your-api-key-here');

            return self::FAILURE;
        }

        $this->info('✅ Ingest API key configured: '.mb_substr($config['key'], 0, 4).'******');

        $this->newLine();

        // Check each feature configuration
        $features = [
            'errors' => 'Error Reporting',
            'events' => 'Event Tracking',
            'website_analytics' => 'Website Analytics',
            'javascript_errors' => 'JavaScript Errors',
            'logging' => 'Centralized Logging',
        ];

        $this->line('📋 <fg=cyan>Feature Configuration:</>');
        $rows = [];

        foreach ($features as $key => $name) {
            if (! isset($config[$key])) {
                $this->warn("⚠️  Feature '{$key}' is missing from config");

                continue;
            }

            $feature = $config[$key];
            $enabled = $feature['enabled'] ?? false;
            $queue = $feature['queue'] ?? false;
            $queueName = $feature['queue_name'] ?? 'default';

            $rows[] = [
                $name,
                $enabled ? '✅ Enabled' : '❌ Disabled',
                $queue ? '✅ Queued' : '⚡ Sync',
                $queueName,
            ];

            // Validate structure
            if (! is_bool($enabled)) {
                $this->warn("⚠️  {$name}: 'enabled' should be boolean, got ".gettype($enabled));
            }

            if (! is_bool($queue)) {
                $this->warn("⚠️  {$name}: 'queue' should be boolean, got ".gettype($queue));
            }
        }

        $this->table(['Feature', 'Status', 'Processing', 'Queue'], $rows);
        $this->newLine();

        // Show warnings if features are disabled
        $enabledFeatures = collect($features)->filter(function ($name, $key) use ($config) {
            return $config[$key]['enabled'] ?? false;
        });

        if ($enabledFeatures->isEmpty()) {
            $this->warn('⚠️  All features are currently disabled.');
            $this->info('💡 Enable features in your .env file:');
            $this->line('   RANETRACE_ERRORS_ENABLED=true');
            $this->line('   RANETRACE_EVENTS_ENABLED=true');
            $this->line('   RANETRACE_WEBSITE_ANALYTICS_ENABLED=true');
            $this->line('   RANETRACE_JAVASCRIPT_ERRORS_ENABLED=true');
            $this->line('   RANETRACE_LOGGING_ENABLED=true');
            $this->newLine();
        }

        // Additional config details. Note: per-item size caps (message/trace/file/
        // context) and the 1000-item batch size are fixed internal constants, not
        // config — so only genuinely configurable settings are shown here.
        if (! empty($config['errors'])) {
            $this->line('⚙️  <fg=cyan>Error Reporting Settings:</>');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Timeout', ($config['errors']['timeout'] ?? 10).' seconds'],
                    ['Queue Name', $config['errors']['queue_name'] ?? 'default'],
                    ['Capture User Email', ($config['errors']['capture_user_email'] ?? false) ? 'Yes' : 'No'],
                ]
            );
            $this->newLine();
        }

        $this->info('✅ Ranetrace configuration is valid!');
        $this->newLine();

        $this->info('📚 Test specific features:');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=errors</> - Test error reporting');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=events</> - Test event tracking');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=logging</> - Test logging');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=javascript_errors</> - JavaScript setup');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=analytics</> - Analytics setup');
        $this->newLine();

        $this->info('Test multiple features:');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=errors,events</> - Test multiple');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --all</> - Test all features');
        $this->line('   • <fg=yellow>php artisan ranetrace:test --feature=all</> - Test all features');
        $this->newLine();

        $this->info('Or use the specific commands:');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-errors</>');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-events</>');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-logging</>');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-javascript-errors</>');
        $this->line('   • <fg=yellow>php artisan ranetrace:test-analytics</>');

        return self::SUCCESS;
    }
}
