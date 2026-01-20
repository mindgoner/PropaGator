<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Facades;

use Illuminate\Support\Facades\Facade;

class Propagator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'propagator';
    }
}
