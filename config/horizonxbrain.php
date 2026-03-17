<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Flow Source
    |--------------------------------------------------------------------------
    |
    | The live flow screen is mock-first while the product shape settles. Switch
    | this to "redis" for Horizon's Redis telemetry or "database" for Laravel's
    | database queue tables, including MySQL and PostgreSQL backed queues.
    |
    */

    'flow' => [
        'source' => env('HORIZONXBRAIN_FLOW_SOURCE', 'mock'),

        'database' => [
            'connections' => [],
            'failed_table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
        ],
    ],
];
