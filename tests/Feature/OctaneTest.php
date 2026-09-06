<?php

use Abhishek\Yardmaster\Entries\RunEntry;
use Abhishek\Yardmaster\Enums\RunStatus;
use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Abhishek\Yardmaster\Yardmaster;
use Illuminate\Support\Facades\DB;

/**
 * Octane keeps the application in memory between requests, so anything the
 * package holds per-request has to be drained and cleared explicitly. The
 * listeners are registered by event name, so these run without Octane
 * installed.
 */
function entry(string $uuid = 'run-1'): RunEntry
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

it('writes each attempt immediately at the default flush threshold', function () {
    SucceedingJob::dispatch();

    // The safe default: an attempt is durable the instant it ends, so a worker
    // killed outright loses nothing.
    expect(app(Yardmaster::class)->buffer()->isEmpty())->toBeTrue()
        ->and(DB::table('yard_runs')->count())->toBe(1);
});

it('clears the pointer once an attempt ends so siblings are not nested', function () {
    $yardmaster = app(Yardmaster::class);

    $yardmaster->enteringJob('parent');
    expect($yardmaster->currentJobUuid())->toBe('parent');

    $yardmaster->leavingJob();
    expect($yardmaster->currentJobUuid())->toBeNull();
});
