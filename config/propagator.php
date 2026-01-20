<?php

declare(strict_types=1);

return [
    'table_prefix' => env('PROPAGATOR_TABLE_PREFIX', 'propagator_'),

    'poll_interval' => (int) env('PROPAGATOR_POLL_INTERVAL', 1),

    'remote_url' => env('PROPAGATOR_REMOTE_URL'),

    'basic_auth' => [
        'key' => env('PROPAGATOR_KEY'),
        'secret' => env('PROPAGATOR_SECRET'),
    ],

    // Timestamps are normalized to UTC internally regardless of app timezone.
    'timezone' => 'UTC',

    'pusher' => [
        'enabled' => env('PROPAGATOR_PUSHER_APP_ID')
            && env('PROPAGATOR_PUSHER_APP_KEY')
            && env('PROPAGATOR_PUSHER_APP_SECRET'),
        'app_id' => env('PROPAGATOR_PUSHER_APP_ID'),
        'key' => env('PROPAGATOR_PUSHER_APP_KEY'),
        'secret' => env('PROPAGATOR_PUSHER_APP_SECRET'),
        'cluster' => env('PROPAGATOR_PUSHER_APP_CLUSTER'),
    ],
];
