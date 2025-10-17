<?php

declare(strict_types=1);

namespace Sorane\Laravel;

use Illuminate\Support\ServiceProvider;
use Sorane\Laravel\Analytics\Middleware\TrackPageVisit;
use Sorane\Laravel\Commands\SoraneAnalyticsTestCommand;
use Sorane\Laravel\Commands\SoraneErrorTestCommand;
use Sorane\Laravel\Commands\SoraneEventTestCommand;
use Sorane\Laravel\Commands\SoraneJavaScriptErrorTestCommand;
use Sorane\Laravel\Commands\SoraneLogTestCommand;
use Sorane\Laravel\Commands\SoranePauseClearCommand;
use Sorane\Laravel\Commands\SoraneStatusCommand;
use Sorane\Laravel\Commands\SoraneTestCommand;
use Sorane\Laravel\Commands\SoraneWorkCommand;
use Sorane\Laravel\Events\EventTracker;
use Sorane\Laravel\Logging\SoraneLogDriver;

class SoraneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__.'/../config/sorane.php',
            'sorane'
        );

        // Auto-register sorane_internal log channel
        // This ensures zero-config setup for internal diagnostics
        $this->app['config']->set('logging.channels.sorane_internal', [
            'driver' => 'daily',
            'path' => storage_path('logs/sorane-internal.log'),
            'level' => config('sorane.internal_logging.level', 'debug'),
            'days' => config('sorane.internal_logging.days', 14),
        ]);

        // Register Sorane as singleton
        $this->app->singleton(Sorane::class, function () {
            return new Sorane;
        });

        // Register EventTracker as singleton
        $this->app->singleton(EventTracker::class, function () {
            return new EventTracker;
        });

        // Register custom log driver
        $this->app['log']->extend('sorane', function ($app, $config) {
            return (new SoraneLogDriver)($config);
        });
    }

    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sorane.php' => config_path('sorane.php'),
            ], 'sorane-config');

            // Register commands
            $this->commands([
                SoraneTestCommand::class,
                SoraneAnalyticsTestCommand::class,
                SoraneErrorTestCommand::class,
                SoraneEventTestCommand::class,
                SoraneJavaScriptErrorTestCommand::class,
                SoraneLogTestCommand::class,
                SoraneWorkCommand::class,
                SoraneStatusCommand::class,
                SoranePauseClearCommand::class,
            ]);
        }

        // Load package views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sorane');

        // Add middleware to web group
        if (config('sorane.enabled', false) && config('sorane.website_analytics.enabled')) {
            $this->app['router']->pushMiddlewareToGroup('web', TrackPageVisit::class);
        }

        // Register JavaScript error tracking route
        if (config('sorane.enabled', false) && config('sorane.javascript_errors.enabled')) {
            $this->registerJavaScriptErrorRoute();
        }

        // Register Blade directive for error tracking script
        $this->registerBladeDirectives();
    }

    protected function registerJavaScriptErrorRoute(): void
    {
        $this->app['router']
            ->post('sorane/js-errors', [Http\Controllers\JavaScriptErrorController::class, 'store'])
            ->middleware(['web', 'throttle:60,1'])
            ->name('sorane.javascript-errors.store');
    }

    protected function registerBladeDirectives(): void
    {
        \Illuminate\Support\Facades\Blade::directive('soraneErrorTracking', function () {
            return "<?php echo view('sorane::error-tracker')->render(); ?>";
        });
    }
}
