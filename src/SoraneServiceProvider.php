<?php

declare(strict_types=1);

namespace Sorane\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Sorane\Laravel\Analytics\Middleware\TrackPageVisit;
use Sorane\Laravel\Commands\SoraneWorkCommand;
use Sorane\Laravel\Commands\SoraneEventTestCommand;
use Sorane\Laravel\Commands\SoraneJavaScriptErrorTestCommand;
use Sorane\Laravel\Commands\SoraneLogTestCommand;
use Sorane\Laravel\Commands\SoraneTestCommand;
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
                SoraneEventTestCommand::class,
                SoraneLogTestCommand::class,
                SoraneJavaScriptErrorTestCommand::class,
                SoraneWorkCommand::class,
            ]);
        }

        // Register scheduled batch sending
        $this->scheduleBatchSending();

        // Load package views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sorane');

        // Add middleware to web group
        if (config('sorane.website_analytics.enabled')) {
            $this->app['router']->pushMiddlewareToGroup('web', TrackPageVisit::class);
        }

        // Register JavaScript error tracking route
        if (config('sorane.javascript_errors.enabled')) {
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

    protected function scheduleBatchSending(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('sorane:work')
                ->everySeconds(60)
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
