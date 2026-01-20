<?php

namespace Mindgoner\Propagator;

use Illuminate\Support\ServiceProvider;
use Mindgoner\Propagator\Services\PropagatorRecorder;

class PropagatorServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../config/propagator.php', 'propagator'
        );

        // Bind the service to the container
        $this->app->singleton('propagator', function ($app) {
            return new PropagatorRecorder();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/propagator.php' => config_path('propagator.php'),
        ], 'propagator-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'propagator-migrations');
    }
}
