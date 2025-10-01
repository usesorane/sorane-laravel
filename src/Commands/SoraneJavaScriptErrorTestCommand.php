<?php

declare(strict_types=1);

namespace Sorane\Laravel\Commands;

use Illuminate\Console\Command;

class SoraneJavaScriptErrorTestCommand extends Command
{
    protected $signature = 'sorane:test-javascript-errors';

    protected $description = 'Display JavaScript error tracking configuration and usage instructions';

    public function handle(): int
    {
        $this->info('🔍 Sorane JavaScript Error Tracking Test');
        $this->newLine();

        // Display current configuration
        $this->line('📋 <fg=cyan>Current Configuration:</>');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', config('sorane.javascript_errors.enabled') ? '✅ Yes' : '❌ No'],
                ['Sample Rate', config('sorane.javascript_errors.sample_rate', 1.0) * 100 .'%'],
                ['Queue Enabled', config('sorane.javascript_errors.queue') ? '✅ Yes' : '❌ No'],
                ['Queue Name', config('sorane.javascript_errors.queue_name', 'default')],
                ['Max Breadcrumbs', config('sorane.javascript_errors.max_breadcrumbs', 20)],
                ['Capture Console Errors', config('sorane.javascript_errors.capture_console_errors') ? '✅ Yes' : '❌ No'],
                ['Ignored Errors', count(config('sorane.javascript_errors.ignored_errors', [])).' pattern(s)'],
            ]
        );

        $this->newLine();

        if (! config('sorane.javascript_errors.enabled')) {
            $this->warn('⚠️  JavaScript error tracking is currently disabled.');
            $this->info('💡 To enable it, add to your .env file:');
            $this->line('   SORANE_JAVASCRIPT_ERRORS_ENABLED=true');
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

        $this->info('✅ JavaScript error tracking is enabled and configured!');
        $this->newLine();

        // Display usage instructions
        $this->line('📖 <fg=cyan>Usage Instructions:</>');
        $this->newLine();

        $this->line('<fg=green>Step 1:</> Add the tracking script to your layout');
        $this->line('Add this directive to your main layout file (e.g., <fg=yellow>resources/views/layouts/app.blade.php</>):');
        $this->newLine();
        $this->line('<fg=white><!DOCTYPE html>');
        $this->line('<html>');
        $this->line('  <head>');
        $this->line('    <title>My App</title>');
        $this->line('    ...');
        $this->line('  </head>');
        $this->line('  <body>');
        $this->line('    @yield(\'content\')');
        $this->line('    ');
        $this->line('    <fg=cyan>@soraneErrorTracking</>');
        $this->line('  </body>');
        $this->line('</html></>');
        $this->newLine(2);

        $this->line('<fg=green>Step 2:</> Test error tracking in your browser');
        $this->line('Open your browser console and run:');
        $this->newLine();
        $this->line('<fg=yellow>throw new Error("Test error from Sorane");</>');
        $this->newLine(2);

        $this->line('<fg=green>Step 3:</> Use the manual API (optional)');
        $this->line('You can manually capture errors or add breadcrumbs:');
        $this->newLine();
        $this->line('<fg=yellow>// Capture a custom error');
        $this->line('try {');
        $this->line('  // Your code here');
        $this->line('} catch (error) {');
        $this->line('  window.Sorane.captureError(error, {');
        $this->line('    custom_field: "value"');
        $this->line('  });');
        $this->line('}');
        $this->newLine();
        $this->line('// Add a breadcrumb for debugging context');
        $this->line('window.Sorane.addBreadcrumb("user", "Button clicked", {');
        $this->line('  button_id: "submit-form"');
        $this->line('});</>');
        $this->newLine(2);

        $this->line('🎉 <fg=green>Features included:</>');
        $this->line('   ✅ Automatic error capture (window.onerror)');
        $this->line('   ✅ Unhandled promise rejection capture');
        $this->line('   ✅ Breadcrumbs (clicks, form submissions, HTTP requests)');
        $this->line('   ✅ Browser info (screen size, memory, connection type)');
        $this->line('   ✅ Error deduplication');
        $this->line('   ✅ Stack traces');
        $this->line('   ✅ User and session tracking');
        $this->newLine();

        $this->info('📚 For more information, visit: https://sorane.io/docs');
        $this->newLine();

        return self::SUCCESS;
    }
}
