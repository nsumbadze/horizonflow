<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Flow Source
    |--------------------------------------------------------------------------
    |
    | The live flow screen reads every configured source by default. This keeps
    | Redis Horizon telemetry and Laravel database queue tables in one graph.
    | Switch this to "redis", "database", or "mock" to force a single source.
    |
    */

    'flow' => [
        'source' => env('HORIZONXBRAIN_FLOW_SOURCE', 'auto'),

        'sources' => ['redis', 'database'],

        'database' => [
            'connections' => [],
            'discover_connections' => env('HORIZONXBRAIN_DISCOVER_DATABASE_QUEUES', true),
            'failed_table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
        ],
    ],
];
