<?php

declare(strict_types=1);

namespace Mindgoner\Propagator;

use Illuminate\Support\ServiceProvider;
use Mindgoner\Propagator\Services\PropagatorRecorder;

class PropagatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/propagator.php', 'propagator');

        $this->app->singleton('propagator', function ($app) {
            return new PropagatorRecorder();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/propagator.php' => config_path('propagator.php'),
        ], 'propagator-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'propagator-migrations');
    }
}
