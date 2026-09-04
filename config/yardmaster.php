<?php

use Iocod\Yardmaster\Ingest\DatabaseIngest;
use Iocod\Yardmaster\Recorders\JobRuns;

return [

    /*
    |---------------------------------------------------------------------------
    | Master Switch
    |---------------------------------------------------------------------------
    | When disabled, Yardmaster registers nothing: no listeners, no payload
    | injection, no writes. Useful for test suites and for cutting recording
    | during an incident without a deploy.
    */

    'enabled' => env('YARDMASTER_ENABLED', true),

    /*
    |---------------------------------------------------------------------------
    | Storage
    |---------------------------------------------------------------------------
    | Point Yardmaster at a dedicated connection on any application that writes
    | more than a trickle of jobs. Telemetry contending with application writes
    | is the single most common way monitoring packages hurt the app they watch.
    */

    'storage' => [
        'connection' => env('YARDMASTER_DB_CONNECTION'),
        'runs_table' => 'yard_runs',
        'buckets_table' => 'yard_buckets',
    ],

    /*
    |---------------------------------------------------------------------------
    | Ingest
    |---------------------------------------------------------------------------
    | 'database' writes buffered entries straight through and needs no extra
    | process. The Redis stream driver arrives in phase 4 for applications that
    | outgrow direct writes.
    */

    'ingest' => [
        'driver' => env('YARDMASTER_INGEST_DRIVER', 'database'),

        'drivers' => [
            'database' => ['via' => DatabaseIngest::class],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Recorders
    |---------------------------------------------------------------------------
    | Each recorder captures one slice of queue activity. 'sample' is the
    | fraction of events kept; counts derived from sampled data are scaled back
    | up and marked as approximate in the dashboard.
    */

    'recorders' => [
        JobRuns::class => [
            'enabled' => true,
            'sample' => (float) env('YARDMASTER_SAMPLE', 1.0),
            'ignore' => [
                // '#^App\\\\Jobs\\\\Noisy#',
            ],
            'capture_payloads' => env('YARDMASTER_CAPTURE_PAYLOADS', true),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Drivers
    |---------------------------------------------------------------------------
    | Live introspection and control. Each driver adapter declares what it can
    | honestly do; the dashboard renders against that declaration rather than
    | assuming. A driver with no adapter falls back to a null adapter: full
    | recorded history, every live control correctly disabled.
    |
    | A queue connection may also carry 'yardmaster_depth_cache' in config/queue.php
    | to override how long a depth reading is reused for that connection.
    */

    'drivers' => [
        'redis' => [
            // Redis has no per-job handle, so deleting or promoting a job means
            // scanning for it. This caps that scan rather than stalling the
            // dashboard on a very large backlog.
            'max_scan' => (int) env('YARDMASTER_REDIS_MAX_SCAN', 10000),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Redaction
    |---------------------------------------------------------------------------
    | A queue dashboard is an unadvertised store of personal data. Redaction is
    | on by default; these patterns are matched against payload keys at any
    | depth, case-insensitively.
    */

    'redact' => [
        'enabled' => env('YARDMASTER_REDACT', true),
        'keys' => [
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
            'authorization', 'auth', 'credit_card', 'card_number', 'cvv',
            'ssn', 'social_security', 'private_key', 'access_token',
            'refresh_token', 'client_secret',
        ],
        'placeholder' => '[redacted]',
    ],

    /*
    |---------------------------------------------------------------------------
    | Retention
    |---------------------------------------------------------------------------
    | Per-attempt rows are the hot, expensive table and are kept for hours or
    | days. Pre-aggregated buckets are cheap and are kept for months, which is
    | what makes long-range trends affordable.
    */

    'retention' => [
        'runs' => env('YARDMASTER_RETAIN_RUNS', '48 hours'),
        'payloads' => env('YARDMASTER_RETAIN_PAYLOADS', '6 hours'),
        'buckets' => [
            'minute' => '24 hours',
            'hour' => '30 days',
            'day' => '400 days',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Tags
    |---------------------------------------------------------------------------
    | Callables that receive a job instance and return extra tags. Tags are
    | injected into the payload at dispatch, so they survive every driver.
    */

    'tags' => [
        'auto_model_tags' => true,
    ],

];
