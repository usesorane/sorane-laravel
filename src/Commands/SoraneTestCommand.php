<?php

declare(strict_types=1);

namespace Sorane\Laravel\Commands;

use Illuminate\Console\Command;

class SoraneTestCommand extends Command
{
    public $signature = 'sorane:test';

    public $description = 'This command will send a test message to Sorane';

    public function handle(): int
    {
        $this->info('🔍 Testing Sorane Configuration...');
        $this->newLine();

        $config = config('sorane');

        // Test the structure of the config file
        if (! is_array($config)) {
            $this->error('❌ Sorane configuration file is not valid.');

            return self::FAILURE;
        }

        // Check API key
        if (empty($config['key'])) {
            $this->error('❌ Sorane API key is not set.');
            $this->info('💡 Add to your .env file: SORANE_KEY=your-api-key-here');

            return self::FAILURE;
        }

        $this->info('✅ API Key configured: '.mb_substr($config['key'], 0, 4).'******');
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
            $this->line('   SORANE_ERRORS_ENABLED=true');
            $this->line('   SORANE_EVENTS_ENABLED=true');
            $this->line('   SORANE_WEBSITE_ANALYTICS_ENABLED=true');
            $this->line('   SORANE_JAVASCRIPT_ERRORS_ENABLED=true');
            $this->line('   SORANE_LOGGING_ENABLED=true');
            $this->newLine();
        }

        // Additional config details
        if (! empty($config['errors'])) {
            $this->line('⚙️  <fg=cyan>Error Reporting Settings:</>');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Timeout', ($config['errors']['timeout'] ?? 10).' seconds'],
                    ['Max File Size', number_format($config['errors']['max_file_size'] ?? 1048576).' bytes'],
                    ['Max Trace Length', number_format($config['errors']['max_trace_length'] ?? 5000).' chars'],
                    ['Batch Size', $config['errors']['batch']['size'] ?? 50],
                ]
            );
            $this->newLine();
        }

        $this->info('✅ Sorane configuration is valid!');
        $this->newLine();

        $this->info('📚 Next steps:');
        $this->line('   • Run <fg=yellow>php artisan sorane:test-events</> to test event tracking');
        $this->line('   • Run <fg=yellow>php artisan sorane:test-logging</> to test logging');
        $this->line('   • Run <fg=yellow>php artisan sorane:test-javascript-errors</> for JS setup');

        return self::SUCCESS;
    }
}
