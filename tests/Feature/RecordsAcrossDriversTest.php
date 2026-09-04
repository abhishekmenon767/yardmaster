<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Tests\Fixtures\FailingJob;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;
use Iocod\Yardmaster\Tests\TestCase;

/**
 * The Plane A guarantee: identical telemetry on every driver, with no driver
 * conditionals anywhere in the recorder. These are the tests that hold that
 * line as drivers are added.
 */
it('records a completed attempt on the sync driver', function () {
    SucceedingJob::dispatch('hello');

    $run = DB::table('yard_runs')->first();

    expect($run)->not->toBeNull()
        ->and($run->job_class)->toBe(SucceedingJob::class)
        ->and($run->connection)->toBe('sync')
        ->and($run->status)->toBe('processed')
        ->and($run->attempt)->toBe(1)
        ->and($run->runtime_ms)->toBeGreaterThan(0)
        ->and($run->wait_ms)->not->toBeNull()
        ->and($run->job_uuid)->not->toBeNull();
});

it('records a completed attempt on the database driver', function () {
    SucceedingJob::dispatch('hello')->onConnection('database');

    expect(DB::table('yard_runs')->count())->toBe(0);

    $this->work('database');

    $run = DB::table('yard_runs')->first();

    expect($run)->not->toBeNull()
        ->and($run->job_class)->toBe(SucceedingJob::class)
        ->and($run->connection)->toBe('database')
        ->and($run->queue)->toBe('default')
        ->and($run->status)->toBe('processed')
        ->and($run->runtime_ms)->toBeGreaterThan(0);
});

it('produces the same row shape regardless of driver', function () {
    SucceedingJob::dispatch('via sync');
    SucceedingJob::dispatch('via database')->onConnection('database')->onQueue('reports');
    $this->work('database', 'reports');

    $rows = DB::table('yard_runs')->orderBy('id')->get();

    expect($rows)->toHaveCount(2);

    // Everything but the connection, the queue and the per-run timings must be
    // indistinguishable. Queue is excluded only because SyncJob::getQueue() is
    // hard-coded to 'sync' in the framework and ignores the requested queue
    // entirely; every real driver is compared including it, below.
    $shape = fn ($row) => collect((array) $row)
        ->except([
            'id', 'uuid', 'job_uuid', 'connection', 'queue', 'queued_at',
            'started_at', 'finished_at', 'wait_ms', 'runtime_ms',
            'peak_memory_kb', 'payload',
        ])
        ->all();

    expect($shape($rows[0]))->toBe($shape($rows[1]))
        ->and($rows[1]->queue)->toBe('reports')
        ->and($rows[0]->connection)->toBe('sync')
        ->and($rows[1]->connection)->toBe('database');
});

it('reports the sync driver\'s pseudo-queue under the name the framework gives it', function () {
    // SyncJob::getQueue() returns the literal string 'sync' regardless of the
    // queue requested. That is the truth about where the job ran, so it is
    // recorded as-is: a dashboard that invents a queue name is worse than one
    // that shows an unfamiliar one.
    SucceedingJob::dispatch()->onQueue('reports');

    expect(DB::table('yard_runs')->value('queue'))->toBe('sync');
});

it('records identically on redis and database, queue name included', function () {
    SucceedingJob::dispatch('via redis')->onConnection('redis')->onQueue('reports');
    $this->work('redis', 'reports');

    SucceedingJob::dispatch('via database')->onConnection('database')->onQueue('reports');
    $this->work('database', 'reports');

    $rows = DB::table('yard_runs')->orderBy('id')->get();

    $shape = fn ($row) => collect((array) $row)
        ->except([
            'id', 'uuid', 'job_uuid', 'connection', 'queued_at', 'started_at',
            'finished_at', 'wait_ms', 'runtime_ms', 'peak_memory_kb', 'payload',
        ])
        ->all();

    expect($rows)->toHaveCount(2)
        ->and($shape($rows[0]))->toBe($shape($rows[1]))
        ->and($rows[0]->queue)->toBe('reports')
        ->and($rows[1]->queue)->toBe('reports');
})->skip(fn () => ! TestCase::redisIsAvailable(), 'requires a running Redis server');

it('records a failure with the exception that caused it', function () {
    FailingJob::dispatch()->onConnection('database');

    $this->work('database');

    $run = DB::table('yard_runs')->where('status', 'failed')->first();

    expect($run)->not->toBeNull()
        ->and($run->exception_class)->toBe(RuntimeException::class)
        ->and($run->exception_message)->toContain('the widget press jammed')
        ->and($run->finished_at)->not->toBeNull();
});

it('records a released attempt separately from the failure that follows it', function () {
    FailingJob::dispatch()->onConnection('database');

    // Two attempts against a max of two: the first releases, the second fails.
    $this->artisan('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--tries' => 2,
    ])->run();

    $this->artisan('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--tries' => 2,
    ])->run();

    $runs = DB::table('yard_runs')->orderBy('id')->pluck('status')->all();

    expect($runs)->toBe(['released', 'failed']);
});
