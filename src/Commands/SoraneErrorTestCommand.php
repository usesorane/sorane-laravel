<?php

declare(strict_types=1);

namespace Sorane\Laravel\Commands;

use Exception;
use Illuminate\Console\Command;
use RuntimeException;
use Sorane\Laravel\Facades\Sorane;

class SoraneErrorTestCommand extends Command
{
    protected $signature = 'sorane:test-errors';

    protected $description = 'Test Sorane error reporting functionality';

    public function handle(): void
    {
        $this->info('Testing Sorane Error Reporting...');

        // Check if error reporting is enabled
        if (! config('sorane.errors.enabled', true)) {
            $this->warn('⚠ Sorane error reporting is disabled. Set SORANE_ERRORS_ENABLED=true in your .env file');
            $this->info('Configuration check completed.');

            return;
        }

        $this->info('✅ Sorane error reporting is enabled');
        $this->newLine();

        // Test 1: Simple Exception
        $this->info('1. Testing simple exception...');
        try {
            throw new Exception('Test exception from Sorane');
        } catch (Exception $e) {
            Sorane::report($e);
            $this->info('   ✓ Simple exception reported');
        }

        // Test 2: RuntimeException
        $this->info('2. Testing RuntimeException...');
        try {
            throw new RuntimeException('Test runtime exception', 500);
        } catch (RuntimeException $e) {
            Sorane::report($e);
            $this->info('   ✓ RuntimeException reported');
        }

        // Test 3: Exception with context (simulated by adding more stack depth)
        $this->info('3. Testing exception with deeper stack trace...');
        try {
            $this->simulateDeepError();
        } catch (Exception $e) {
            Sorane::report($e);
            $this->info('   ✓ Exception with stack trace reported');
        }

        // Test 4: Custom exception with context
        $this->info('4. Testing exception with custom message...');
        try {
            throw new Exception('Database connection failed: Connection refused on localhost:5432');
        } catch (Exception $e) {
            Sorane::report($e);
            $this->info('   ✓ Custom exception reported');
        }

        $this->newLine();
        $this->info('✅ All test errors have been sent to Sorane!');
        $this->info('Check your Sorane dashboard to see the error reports.');

        $this->newLine();
        $this->info('What gets reported:');
        $this->table(
            ['Data Point', 'Description'],
            [
                ['Exception Message', 'The error message'],
                ['Exception Type', 'The exception class name'],
                ['File & Line', 'Where the error occurred'],
                ['Stack Trace', 'Full execution path (truncated if too long)'],
                ['Code Context', '11 lines around the error (5 before, error, 5 after)'],
                ['Request Info', 'URL, method, headers (sensitive data masked)'],
                ['User Info', 'Authenticated user ID and email (if available)'],
                ['Environment', 'Application environment (production, local, etc.)'],
                ['Versions', 'PHP and Laravel versions'],
            ]
        );

        $this->newLine();
        $this->info('Error reporting configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Errors Enabled', config('sorane.errors.enabled') ? 'Yes' : 'No'],
                ['Queue Enabled', config('sorane.errors.queue') ? 'Yes' : 'No'],
                ['Queue Name', config('sorane.errors.queue_name')],
                ['Timeout', config('sorane.errors.timeout').' seconds'],
                ['Max File Size', number_format(config('sorane.errors.max_file_size')).' bytes'],
                ['Max Trace Length', number_format(config('sorane.errors.max_trace_length')).' chars'],
                ['Batch Size', config('sorane.errors.batch.size')],
                ['API Key Set', config('sorane.key') ? 'Yes' : 'No'],
            ]
        );

        $this->newLine();
        $this->info('Privacy & Security:');
        $this->table(
            ['Item', 'How It\'s Handled'],
            [
                ['Sensitive Headers', 'Cookie, Authorization, X-CSRF-Token are masked'],
                ['Code Context', 'Only included if file is readable and under size limit'],
                ['Stack Trace', 'Truncated if exceeds max length'],
                ['User Data', 'Only ID and email (no passwords or sensitive data)'],
            ]
        );

        $this->newLine();
        $this->info('Usage in your code:');
        $this->line('<fg=yellow>try {');
        $this->line('    // Your code here');
        $this->line('} catch (Exception $e) {');
        $this->line('    Sorane::report($e);');
        $this->line('    throw $e; // Re-throw if needed');
        $this->line('}</>');
        $this->newLine();

        $this->line('<fg=green>Or use Laravel\'s exception handler:</> (recommended)');
        $this->line('Add to <fg=yellow>app/Exceptions/Handler.php</>:');
        $this->line('<fg=yellow>public function register(): void');
        $this->line('{');
        $this->line('    $this->reportable(function (Throwable $e) {');
        $this->line('        if (app()->bound(\'sorane\')) {');
        $this->line('            app(\'sorane\')->report($e);');
        $this->line('        }');
        $this->line('    });');
        $this->line('}</>');
    }

    /**
     * Simulate a deeper call stack for testing.
     */
    protected function simulateDeepError(): void
    {
        $this->levelOne();
    }

    protected function levelOne(): void
    {
        $this->levelTwo();
    }

    protected function levelTwo(): void
    {
        $this->levelThree();
    }

    protected function levelThree(): void
    {
        throw new Exception('Test exception with deeper stack trace');
    }
}
