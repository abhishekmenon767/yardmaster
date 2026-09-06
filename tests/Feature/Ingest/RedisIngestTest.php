<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Contracts\Ingest;
use Iocod\Yardmaster\Entries\RunEntry;
use Iocod\Yardmaster\Enums\RunStatus;
use Iocod\Yardmaster\Ingest\RedisIngest;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;
use Iocod\Yardmaster\Tests\TestCase;

beforeEach(function () {
    if (! TestCase::redisIsAvailable()) {
        $this->markTestSkipped('requires a running Redis server');
    }

    config()->set('yardmaster.ingest.driver', 'redis');

    $this->ingest = app(Ingest::class);
    $this->stream = $this->ingest->stream();
    app('redis')->connection('default')->del($this->stream);
});

afterEach(function () {
    if (TestCase::redisIsAvailable()) {
        app('redis')->connection('default')->del($this->stream);
        $this->flushRedis();
    }
});

it('resolves the redis driver from config', function () {
    expect($this->ingest)->toBeInstanceOf(RedisIngest::class);
});

it('buffers a job to the stream instead of writing to the database', function () {
    SucceedingJob::dispatch();

    expect(DB::table('yard_runs')->count())->toBe(0)
        ->and($this->ingest->pending())->toBe(1);
});

it('round trips an entry through the stream without losing anything', function () {
    $entry = new RunEntry(
        uuid: 'run-1',
        jobUuid: 'job-1',
        jobClass: 'App\\Jobs\\Example',
        connection: 'redis',
        queue: 'reports',
        attempt: 2,
        status: RunStatus::Failed,
        queuedAt: 1000.5,
        startedAt: 1001.25,
        finishedAt: 1002.5,
        waitMs: 750.0,
        runtimeMs: 1250.0,
        peakMemoryKb: 4096,
        batchId: 'batch-9',
        parentUuid: 'parent-3',
        tags: ['App\\Models\\Order:1'],
        payload: ['uuid' => 'job-1'],
        exceptionClass: RuntimeException::class,
        exceptionMessage: 'nope',
        sampleRate: 0.5,
    );

    $this->ingest->ingest([$entry]);

    $batches = $this->ingest->read(10);
    $decoded = reset($batches)[0];

    expect($decoded->toArray())->toBe($entry->toArray());
});

it('drains the stream into storage and clears what it wrote', function () {
    SucceedingJob::dispatch();
    SucceedingJob::dispatch();

    $this->artisan('yard:work', ['--once' => true])->assertSuccessful();

    expect(DB::table('yard_runs')->count())->toBe(2)
        ->and(DB::table('yard_buckets')->count())->toBeGreaterThan(0)
        ->and($this->ingest->pending())->toBe(0);
});

it('leaves nothing behind for a second drain to double count', function () {
    SucceedingJob::dispatch();

    $this->artisan('yard:work', ['--once' => true])->assertSuccessful();
    $this->artisan('yard:work', ['--once' => true])->assertSuccessful();

    expect(DB::table('yard_runs')->count())->toBe(1);
});

it('refuses to drain while another drainer holds the lease', function () {
    SucceedingJob::dispatch();

    cache()->store()->lock('yardmaster:work', 60)->get();

    $this->artisan('yard:work', ['--once' => true])->assertSuccessful();

    // Two drainers reading the same stream would both see this batch and write
    // it twice, quietly doubling every count on the dashboard.
    expect(DB::table('yard_runs')->count())->toBe(0)
        ->and($this->ingest->pending())->toBe(1);
});

it('discards an unreadable batch rather than blocking every batch behind it', function () {
    app('redis')->connection('default')->xadd($this->stream, ['entries' => 'not json']);
    SucceedingJob::dispatch();

    $this->artisan('yard:work', ['--once' => true])->assertSuccessful();

    expect($this->ingest->pending())->toBe(0)
        ->and(DB::table('yard_runs')->count())->toBe(1);
});

it('says plainly that the database driver needs no daemon', function () {
    config()->set('yardmaster.ingest.driver', 'database');

    // The ingest driver is a singleton, so a config change alone does not
    // re-resolve it — which is correct in production and worth stating here.
    app()->forgetInstance(Ingest::class);

    $this->artisan('yard:work', ['--once' => true])
        ->expectsOutputToContain('nothing to drain')
        ->assertSuccessful();
});
