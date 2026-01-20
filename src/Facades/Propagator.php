<?php

namespace Mindgoner\Propagator\Facades;

use Illuminate\Support\Facades\Facade;

class Propagator extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'propagator';
    }
}
