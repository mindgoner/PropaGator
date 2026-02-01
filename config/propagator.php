<?php

declare(strict_types=1);

return [
    'table_prefix' => env('PROPAGATOR_TABLE_PREFIX', 'propagator_'),

    'poll_interval' => (int) env('PROPAGATOR_POLL_INTERVAL', 1),

    'remote_url' => env('PROPAGATOR_REMOTE_URL'),

    'local_base_url' => env('PROPAGATOR_LOCAL_URL', env('APP_URL', 'http://localhost')),

    'basic_auth' => [
        'key' => env('PROPAGATOR_KEY'),
        'secret' => env('PROPAGATOR_AUTH_SECRET', env('PROPAGATOR_SECRET')),
    ],

    'pull_path' => env('PROPAGATOR_PULL_PATH', '/propagator/pull'),

    // Timestamps are normalized to UTC internally regardless of app timezone.
    'timezone' => 'UTC',

    'shared_secret' => env('PROPAGATOR_SECRET'),

];
