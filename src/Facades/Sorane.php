<?php

namespace Sorane\Sorane\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Sorane\Sorane\Sorane
 */
class Sorane extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sorane\Sorane\Sorane::class;
    }
}
