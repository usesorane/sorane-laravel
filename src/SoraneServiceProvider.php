<?php

namespace Sorane\ErrorReporting;

use Sorane\ErrorReporting\Analytics\Middleware\TrackPageVisit;
use Sorane\ErrorReporting\Commands\SoraneTestCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SoraneServiceProvider extends PackageServiceProvider
{
    public function bootingPackage(): void
    {
        if (config('sorane.website_analytics.enabled')) {
            $this->app['router']->pushMiddlewareToGroup('web', TrackPageVisit::class);
        }
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('sorane-laravel')
            ->hasConfigFile('sorane')
            ->hasCommand(SoraneTestCommand::class);
    }
}
