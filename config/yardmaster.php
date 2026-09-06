<?php

use Abhishek\Yardmaster\Ingest\DatabaseIngest;
use Abhishek\Yardmaster\Ingest\RedisIngest;
use Abhishek\Yardmaster\Recorders\JobRuns;
use Abhishek\Yardmaster\Recorders\WorkerHeartbeat;

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
        'actions_table' => 'yard_actions',
        'issues_table' => 'yard_issues',
        'workers_table' => 'yard_workers',
    ],

    /*
    |---------------------------------------------------------------------------
    | Dashboard
    |---------------------------------------------------------------------------
    | Two gates guard it, and they are separate on purpose: 'viewYardmaster'
    | for reading, 'manageYardmaster' for anything that changes queue state.
    | Neither is granted outside the local environment until you define it.
    */

    'dashboard' => [
        'enabled' => env('YARDMASTER_DASHBOARD', true),
        'path' => env('YARDMASTER_PATH', 'yardmaster'),
        'domain' => env('YARDMASTER_DOMAIN'),
        'middleware' => ['web'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Ingest
    |---------------------------------------------------------------------------
    | 'database' writes buffered entries straight through and needs no extra
    | process — the right default, and fine well past most applications' volume.
    |
    | 'redis' turns a flush into a single XADD and moves aggregation onto a
    | `yard:work` daemon. Use a different Redis connection from any Redis-backed
    | queue: telemetry competing with the queue it measures is a poor trade.
    */

    'ingest' => [
        'driver' => env('YARDMASTER_INGEST_DRIVER', 'database'),

        /*
         * Entries buffered before the write happens. One makes every attempt
         * durable the instant it ends; raising it amortises the aggregate write
         * across a batch and risks losing at most this many attempts if a
         * worker is killed outright. What is at risk is telemetry, never work.
         */
        'flush_threshold' => (int) env('YARDMASTER_FLUSH_THRESHOLD', 1),

        'drivers' => [
            'database' => ['via' => DatabaseIngest::class],

            'redis' => [
                'via' => RedisIngest::class,
                'connection' => env('YARDMASTER_REDIS_CONNECTION', 'default'),
                'stream' => 'yardmaster:ingest',
                // Capped on purpose. A drainer that falls behind or dies costs
                // bounded memory and loses the oldest telemetry, rather than
                // filling the Redis instance the application depends on.
                'trim' => (int) env('YARDMASTER_INGEST_TRIM', 10000),
                'chunk' => 250,
            ],
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

        WorkerHeartbeat::class => [
            'enabled' => true,
            // Seconds between heartbeats. The roster answers "is anything
            // consuming this queue, and has it wedged" — a question that does
            // not need sub-second accuracy, and would cost a query between
            // every job to get it.
            'interval' => (int) env('YARDMASTER_HEARTBEAT', 5),
            // A worker unheard from for this long is presumed gone.
            'stale_after' => (int) env('YARDMASTER_WORKER_STALE', 30),
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
        // Workers killed outright never remove their own row. This bounds how
        // long those linger, which matters for `--once` workers whose pid —
        // and therefore whose row — changes on every invocation.
        'workers' => env('YARDMASTER_RETAIN_WORKERS', '10 minutes'),
        'buckets' => [
            'minute' => '24 hours',
            'hour' => '30 days',
            'day' => '400 days',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Alerting
    |---------------------------------------------------------------------------
    | Two thresholds per rule, not one. A rule that fires and clears at the same
    | number flaps, and an alerting system that cries wolf gets muted — after
    | which it is worse than having none, because everyone believes it is
    | watching. Schedule `yard:check` every minute.
    |
    | Metrics: backlog | oldest_job_age | failure_rate | p95_runtime
    */

    'alerts' => [
        'enabled' => env('YARDMASTER_ALERTS', false),

        'notify' => [
            'mail' => env('YARDMASTER_ALERT_MAIL'),
        ],

        'rules' => [
            // [
            //     'name' => 'Default queue backing up',
            //     'connection' => 'redis',
            //     'queue' => 'default',
            //     'metric' => 'backlog',
            //     'above' => 5000,
            //     'recovers_below' => 1000,  // defaults to 80% of 'above'
            //     'for' => 3,                // consecutive checks before firing
            //     'cooldown' => 900,         // quiet period once it has spoken
            // ],
            // [
            //     'name' => 'Jobs failing',
            //     'metric' => 'failure_rate',
            //     'above' => 0.05,
            //     'window' => 300,
            //     'for' => 2,
            // ],
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
