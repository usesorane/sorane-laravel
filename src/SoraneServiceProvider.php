<?php

namespace Sorane\ErrorReporting;

use Illuminate\Support\ServiceProvider;
use Sorane\ErrorReporting\Analytics\Middleware\TrackPageVisit;
use Sorane\ErrorReporting\Commands\SoraneEventTestCommand;
use Sorane\ErrorReporting\Commands\SoraneLogTestCommand;
use Sorane\ErrorReporting\Commands\SoraneTestCommand;
use Sorane\ErrorReporting\Events\EventTracker;
use Sorane\ErrorReporting\Logging\SoraneLogDriver;

class SoraneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__.'/../config/sorane.php',
            'sorane'
        );

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
            ]);
        }

        // Add middleware to web group
        if (config('sorane.website_analytics.enabled')) {
            $this->app['router']->pushMiddlewareToGroup('web', TrackPageVisit::class);
        }
    }
}
