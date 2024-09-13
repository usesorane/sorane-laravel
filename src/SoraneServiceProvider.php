<?php

namespace Sorane\Sorane;

use Sorane\Sorane\Commands\SoraneTestCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SoraneServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('sorane-laravel')
            ->hasConfigFile()
            ->hasCommand(SoraneTestCommand::class);
    }
}
