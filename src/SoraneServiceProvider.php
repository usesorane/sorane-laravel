<?php

namespace Sorane\ErrorReporting;

use Sorane\ErrorReporting\Analytics\Middleware\TrackPageVisit;
use Sorane\ErrorReporting\Commands\SoraneEventTestCommand;
use Sorane\ErrorReporting\Commands\SoraneTestCommand;
use Sorane\ErrorReporting\Events\EventTracker;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SoraneServiceProvider extends PackageServiceProvider
{
    public function bootingPackage(): void
    {
        if (config('sorane.website_analytics.enabled')) {
            $this->app['router']->pushMiddlewareToGroup('web', TrackPageVisit::class);
        }

        // Register EventTracker as singleton
        $this->app->singleton(EventTracker::class, function () {
            return new EventTracker;
        });
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('sorane-laravel')
            ->hasConfigFile('sorane')
            ->hasCommands([
                SoraneTestCommand::class,
                SoraneEventTestCommand::class,
            ]);
    }
}
