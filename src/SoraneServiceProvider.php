<?php

namespace Sorane\ErrorReporting;

use Sorane\ErrorReporting\Commands\SoraneTestCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SoraneServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('sorane-laravel')
            ->hasConfigFile('sorane')
            ->hasCommand(SoraneTestCommand::class);
    }
}
