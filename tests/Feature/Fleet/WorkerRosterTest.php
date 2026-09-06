<?php

use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->grantDashboard());

it('records a worker while it is consuming a queue', function () {
    Queue::connection('database')->pushOn('reports', new SucceedingJob);

    $this->work('database', 'reports');

    $worker = (array) DB::table('yard_workers')->first();

    expect($worker['connection'])->toBe('database')
        ->and($worker['pid'])->toBe(getmypid())
        ->and((int) $worker['processed'])->toBeGreaterThanOrEqual(0);
});

it('removes itself when the worker shuts down gracefully', function () {
    Queue::connection('database')->pushOn('reports', new SucceedingJob);
    $this->work('database', 'reports');

    expect(DB::table('yard_workers')->count())->toBe(1);

    // A worker that has stopped is not a worker; leaving a tombstone would make
    // the roster something to interpret rather than something to read.
    // `queue:work --once` never reaches this event — it does not enter the
    // daemon loop — so those rows age out through staleness instead.
    event(new WorkerStopping);

    expect(DB::table('yard_workers')->count())->toBe(0);
});

it('reports a live worker through the API', function () {
    DB::table('yard_workers')->insert([
        'name' => 'web-1:4242',
        'host' => 'web-1',
        'pid' => 4242,
        'connection' => 'redis',
        'queues' => 'default,reports',
        'status' => 'working',
        'current_job' => 'App\\Jobs\\SendInvoice',
        'current_job_started_at' => microtime(true) - 3,
        'memory_kb' => 65536,
        'processed' => 120,
        'failed' => 2,
        'started_at' => microtime(true) - 600,
        'last_seen' => microtime(true),
    ]);

    $worker = $this->getJson('yardmaster/api/v1/workers')->assertOk()->json('data.0');

    expect($worker['host'])->toBe('web-1')
        ->and($worker['queues'])->toBe(['default', 'reports'])
        ->and($worker['status'])->toBe('working')
        ->and($worker['current_job_seconds'])->toBeGreaterThan(2)
        ->and($worker['uptime_seconds'])->toBeGreaterThan(500);
});

it('marks a silent worker stale rather than pretending it is fine', function () {
    DB::table('yard_workers')->insert([
        'name' => 'web-2:99',
        'host' => 'web-2',
        'pid' => 99,
        'connection' => 'database',
        'queues' => 'default',
        'status' => 'working',
        'started_at' => microtime(true) - 900,
        'last_seen' => microtime(true) - 300,
    ]);

    $worker = $this->getJson('yardmaster/api/v1/workers')->json('data.0');

    // Silence is the symptom: a worker stuck mid-job stops heartbeating while
    // still claiming to be working.
    expect($worker['status'])->toBe('stale')
        ->and($worker['silent_for_seconds'])->toBeGreaterThan(200);
});

it('prunes workers that were killed without a chance to clean up', function () {
    DB::table('yard_workers')->insert([
        'name' => 'gone:1', 'host' => 'gone', 'pid' => 1,
        'connection' => 'database', 'queues' => 'default', 'status' => 'idle',
        'started_at' => microtime(true) - 7200, 'last_seen' => microtime(true) - 1800,
    ]);

    $this->artisan('yard:trim')->assertSuccessful();

    expect(DB::table('yard_workers')->count())->toBe(0);
});
