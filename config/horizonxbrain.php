<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Flow Source
    |--------------------------------------------------------------------------
    |
    | Horizon processes Redis-backed queues, so the live flow screen defaults
    | to Redis telemetry only. Database queue inspection remains available as
    | an opt-in source for applications that explicitly enable it.
    |
    */

    'flow' => [
        'source' => env('HORIZONXBRAIN_FLOW_SOURCE', 'redis'),

        'sources' => ['redis'],

        'database' => [
            'connections' => [],
            'discover_connections' => env('HORIZONXBRAIN_DISCOVER_DATABASE_QUEUES', false),
            'failed_table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
        ],
    ],
];
