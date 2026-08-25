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
        'source' => env('HORIZONXFLOW_FLOW_SOURCE', 'redis'),

        'sources' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('HORIZONXFLOW_FLOW_SOURCES', 'redis'))
        ))),

        'database' => [
            'connections' => [],
            'discover_connections' => env('HORIZONXFLOW_DISCOVER_DATABASE_QUEUES', false),
            'failed_table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
        ],

        'recent_jobs' => [
            'max' => (int) env('HORIZONXFLOW_FLOW_RECENT_JOBS_MAX', 50),
        ],

        'cache' => [
            'queue_keys_ttl' => (int) env('HORIZONXFLOW_FLOW_QUEUE_KEYS_TTL', 10),
            'payload_ttl' => (int) env('HORIZONXFLOW_FLOW_PAYLOAD_TTL', 3),
        ],
    ],
];
