<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mindgoner\Propagator\Http\Controllers\PropagatorPullController;

Route::get(config('propagator.pull_path', '/propagator/pull'), [PropagatorPullController::class, 'pull']);
