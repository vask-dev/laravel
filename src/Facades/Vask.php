<?php

declare(strict_types=1);

namespace Vask\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Vask\Laravel\Vask
 */
class Vask extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vask\Laravel\Vask::class;
    }
}
