<?php

namespace Sorane\Laravel\Facades;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @see \Sorane\Laravel\Sorane
 *
 * @method static void trackEvent(string $eventName, array $properties = [], ?int $userId = null, bool $validate = true)
 */
class Sorane extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sorane\Laravel\Sorane::class;
    }

    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->reportable(static function (Throwable $exception): \Sorane\Laravel\Sorane {
            $sorane = app(\Sorane\Laravel\Sorane::class);

            $sorane->report($exception);

            return $sorane;
        });
    }
}
