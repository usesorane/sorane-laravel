<?php

namespace Sorane\ErrorReporting\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Foundation\Configuration\Exceptions;
use Throwable;

/**
 * @see \Sorane\ErrorReporting\Sorane
 */
class Sorane extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sorane\ErrorReporting\Sorane::class;
    }

    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->reportable(static function (Throwable $exception): \Sorane\ErrorReporting\Sorane {
            $sorane = app(\Sorane\ErrorReporting\Sorane::class);

            $sorane->report($exception);

            return $sorane;
        });
    }
}
