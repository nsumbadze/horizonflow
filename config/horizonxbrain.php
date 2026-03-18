<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Flow Source
    |--------------------------------------------------------------------------
    |
    | The live flow screen reads Horizon's Redis telemetry by default, matching
    | standard Horizon installs. Switch this to "mock" for demo data or
    | "database" for Laravel database queue tables, including MySQL and
    | PostgreSQL backed queues.
    |
    */

    'flow' => [
        'source' => env('HORIZONXBRAIN_FLOW_SOURCE', 'redis'),

        'database' => [
            'connections' => [],
            'failed_table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
        ],
    ],
];
