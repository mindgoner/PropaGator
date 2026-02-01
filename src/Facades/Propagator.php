<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Facades;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Mindgoner\Propagator\Models\PropagatorRequest record(Request $request)
 */
class Propagator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'propagator';
    }
}
