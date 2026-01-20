<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Propagator Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the Propagator package.
    |
    */

    /**
     * Enable or disable propagation recording.
     */
    'enabled' => env('PROPAGATOR_ENABLED', true),

    /**
     * Storage driver for propagation records.
     * Options: 'database', 'file', 'log'
     */
    'driver' => env('PROPAGATOR_DRIVER', 'database'),

    /**
     * Database connection to use for storing records.
     */
    'connection' => env('PROPAGATOR_DB_CONNECTION', null),

    /**
     * Table name for storing propagation records.
     */
    'table' => env('PROPAGATOR_TABLE', 'propagations'),

];
