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

    /*
    |--------------------------------------------------------------------------
    | Job Dispatching
    |--------------------------------------------------------------------------
    |
    | Live Flow can dispatch a queued job from the dashboard. Discovery walks
    | the configured paths for classes implementing ShouldQueue, while the
    | allow and deny lists constrain what an operator may actually dispatch.
    | An empty allow list permits every discovered job.
    |
    */

    'dispatch' => [
        'enabled' => env('HORIZONXFLOW_DISPATCH_ENABLED', true),

        'discover' => env('HORIZONXFLOW_DISPATCH_DISCOVER', true),

        'paths' => [],

        'allowed' => [],

        'denied' => [],

        'max_delay' => (int) env('HORIZONXFLOW_DISPATCH_MAX_DELAY', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Run Cancellation
    |--------------------------------------------------------------------------
    |
    | A job may declare a cancellation group so that a whole run — a chained
    | walk, or a fan-out of related jobs — can be stopped at once. Cancelling
    | a run purges its pending jobs, drops matching jobs as workers pick them
    | up, and stands until it expires or an operator lifts it.
    |
    */

    'cancellation' => [
        'run_ttl' => (int) env('HORIZONXFLOW_CANCELLED_RUN_TTL', 3600),

        'purge_limit' => (int) env('HORIZONXFLOW_CANCELLED_RUN_PURGE_LIMIT', 5000),
    ],
];
