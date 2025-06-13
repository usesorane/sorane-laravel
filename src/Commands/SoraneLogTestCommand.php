<?php

namespace Sorane\ErrorReporting\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SoraneLogTestCommand extends Command
{
    protected $signature = 'sorane:test-logging';

    protected $description = 'Test Sorane logging functionality';

    public function handle(): void
    {
        $this->info('Testing Sorane Logging...');

        // Check if logging is enabled
        if (! config('sorane.logging.enabled', false)) {
            $this->warn('⚠ Sorane logging is disabled. Enable it in config/sorane.php or set SORANE_LOGGING_ENABLED=true');
            $this->info('Configuration check completed.');

            return;
        }

        $this->info('✅ Sorane logging is enabled');

        // Test Laravel logging integration
        $this->info('1. Testing Laravel Log integration...');

        Log::channel('sorane')->emergency('Test emergency via Laravel Log', [
            'test_context' => 'laravel_log_test',
            'timestamp' => now()->toISOString(),
        ]);
        $this->info('   ✓ Emergency log sent via Laravel Log');

        Log::channel('sorane')->error('Test error via Laravel Log', [
            'test_context' => 'laravel_log_test',
            'error_code' => 'TEST_001',
        ]);
        $this->info('   ✓ Error log sent via Laravel Log');

        // Test stack logging if available
        if (config('logging.channels.production') || config('logging.channels.development')) {
            try {
                Log::stack(['sorane'])->critical('Test stack logging', [
                    'test_context' => 'stack_test',
                    'component' => 'testing',
                ]);
                $this->info('   ✓ Stack log sent');
            } catch (\Exception $e) {
                $this->warn('   ⚠ Stack logging failed: '.$e->getMessage());
            }
        }

        // Test different log levels
        $this->info('2. Testing different log levels...');

        Log::channel('sorane')->warning('Test warning via Laravel Log', [
            'test_context' => 'level_test',
            'level' => 'warning',
        ]);
        $this->info('   ✓ Warning log sent');

        Log::channel('sorane')->notice('Test notice via Laravel Log', [
            'test_context' => 'level_test',
            'level' => 'notice',
        ]);
        $this->info('   ✓ Notice log sent');

        // Test with context
        $this->info('3. Testing with context data...');
        Log::channel('sorane')->error('Error with rich context', [
            'user_id' => 123,
            'order_id' => 'ORD-456',
            'payment_method' => 'stripe',
            'error_code' => 'PAYMENT_FAILED',
            'metadata' => [
                'attempt' => 3,
                'max_attempts' => 5,
            ],
        ]);
        $this->info('   ✓ Context-rich log sent');

        // Test multiple logs (simulating batch)
        $this->info('4. Testing multiple log entries...');
        Log::channel('sorane')->error('First error', ['sequence' => 1]);
        Log::channel('sorane')->warning('Second warning', ['sequence' => 2]);
        Log::channel('sorane')->critical('Third critical', ['sequence' => 3]);
        $this->info('   ✓ Multiple logs sent');

        $this->info('✅ All test logs have been sent to Sorane!');
        $this->info('Check your Sorane dashboard to see the log entries.');

        $this->newLine();
        $this->info('Recommended Laravel logging configuration:');
        $this->line('Add this to your config/logging.php channels array:');
        $this->line('');
        $this->line("'sorane' => [");
        $this->line("    'driver' => 'sorane',");
        $this->line("    'level' => 'error',");
        $this->line('],');
        $this->line('');
        $this->line("'production' => [");
        $this->line("    'driver' => 'stack',");
        $this->line("    'channels' => ['single', 'sorane'],");
        $this->line("    'ignore_exceptions' => false,");
        $this->line('],');
        $this->line('');
        $this->line('Then set LOG_CHANNEL=production in your .env file');

        $this->newLine();
        $this->info('Logging configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Logging Enabled', config('sorane.logging.enabled') ? 'Yes' : 'No'],
                ['Queue Enabled', config('sorane.logging.queue') ? 'Yes' : 'No'],
                ['Queue Name', config('sorane.logging.queue_name')],
                ['Allowed Levels', $this->getFormattedLevels()],
                ['Excluded Channels', implode(', ', config('sorane.logging.excluded_channels', []))],
                ['API Key Set', config('sorane.key') ? 'Yes' : 'No'],
            ]
        );

        $this->newLine();
        $this->info('Primary usage methods:');
        $this->table(
            ['Method', 'Usage', 'Recommended'],
            [
                ['Log::channel(\'sorane\')', 'Direct Sorane channel', 'For Sorane-only logs'],
                ['Log::stack([\'single\', \'sorane\'])', 'Multiple destinations', 'For important logs'],
                ['Log::error() with stack config', 'Automatic dual logging', '✅ Primary method'],
            ]
        );
    }

    private function getFormattedLevels(): string
    {
        $levels = config('sorane.logging.levels');

        if (is_string($levels)) {
            return $levels;
        }

        if (is_array($levels)) {
            return implode(', ', $levels);
        }

        return 'error, critical, alert, emergency (default)';
    }
}
