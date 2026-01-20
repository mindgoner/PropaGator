<?php

declare(strict_types=1);

namespace Mindgoner\Propagator\Models;

use Illuminate\Database\Eloquent\Model;

class PropagatorRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'requestHeaders' => 'array',
        'requestQueryParams' => 'array',
        'requestReceivedAt' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('propagator.table_prefix', 'propagator_') . 'requests';
    }
}
