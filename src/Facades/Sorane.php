<?php

namespace Sorane\ErrorReporting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Sorane\ErrorReporting\Sorane
 */
class Sorane extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sorane\ErrorReporting\Sorane::class;
    }
}
