<?php

use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Entries\RunEntry;
use Iocod\Yardmaster\Enums\RunStatus;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;
use Iocod\Yardmaster\Yardmaster;

function buffered(string $uuid = 'run-1'): RunEntry
{
    return new RunEntry(
        uuid: $uuid,
        jobUuid: 'job-1',
        jobClass: 'App\\Jobs\\Example',
        connection: 'database',
        queue: 'default',
        attempt: 1,
        status: RunStatus::Processed,
        queuedAt: microtime(true),
        startedAt: microtime(true),
        finishedAt: microtime(true),
        runtimeMs: 12.0,
    );
}

it('holds entries back until the threshold is reached', function () {
    foreach (range(1, 5) as $i) {
        SucceedingJob::dispatch("run {$i}");
    }

    // The aggregate write is the expensive half; batching pays it once instead
    // of five times.
    expect(app(Yardmaster::class)->buffer()->count())->toBe(5)
        ->and(DB::table('yard_runs')->count())->toBe(0);
});

it('writes everything buffered as the worker winds down', function () {
    SucceedingJob::dispatch();

    expect(DB::table('yard_runs')->count())->toBe(0);

    // Long-lived workers never reach the framework's terminating callbacks, so
    // this is the hook that stops a graceful shutdown losing telemetry.
    event(new WorkerStopping);

    expect(DB::table('yard_runs')->count())->toBe(1)
        ->and(app(Yardmaster::class)->buffer()->isEmpty())->toBeTrue();
});

it('drains the buffer when Octane terminates a request', function () {
    $yardmaster = app(Yardmaster::class);
    $yardmaster->record(buffered());

    expect($yardmaster->buffer()->count())->toBe(1);

    event('Laravel\Octane\Events\RequestTerminated');

    expect($yardmaster->buffer()->isEmpty())->toBeTrue()
        ->and(DB::table('yard_runs')->count())->toBe(1);
});

it('does not let one Octane request inherit the last one’s buffer', function () {
    $yardmaster = app(Yardmaster::class);
    $yardmaster->enteringJob('a-job-from-the-previous-request');
    $yardmaster->record(buffered());

    event('Laravel\Octane\Events\RequestReceived');

    expect($yardmaster->currentJobUuid())->toBeNull()
        ->and($yardmaster->buffer()->isEmpty())->toBeTrue();
});
