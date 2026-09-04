<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Buffer;
use Iocod\Yardmaster\Contracts\Ingest;
use Iocod\Yardmaster\Entries\RunEntry;
use Iocod\Yardmaster\Enums\RunStatus;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;
use Iocod\Yardmaster\Yardmaster;

/**
 * The non-negotiable invariant: a Yardmaster failure must never become an
 * application failure. If these ever fail, the package is unsafe to install.
 */
it('does not fail the job when ingest throws', function () {
    app()->bind(Ingest::class, fn () => new class implements Ingest
    {
        public function ingest(array $entries): void
        {
            throw new RuntimeException('storage is on fire');
        }
    });

    SucceedingJob::dispatch();

    expect(true)->toBeTrue();
});

it('surfaces swallowed exceptions to a handler when one is registered', function () {
    $caught = null;

    app(Yardmaster::class)->handleExceptionsUsing(function (Throwable $e) use (&$caught) {
        $caught = $e;
    });

    app()->bind(Ingest::class, fn () => new class implements Ingest
    {
        public function ingest(array $entries): void
        {
            throw new RuntimeException('storage is on fire');
        }
    });

    SucceedingJob::dispatch();

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toBe('storage is on fire');
});

it('drops the oldest entries rather than growing without bound', function () {
    $buffer = new Buffer(limit: 3);

    foreach (range(1, 5) as $i) {
        $buffer->push(new RunEntry(
            uuid: (string) $i,
            jobUuid: null,
            jobClass: 'Job',
            connection: 'sync',
            queue: 'default',
            attempt: 1,
            status: RunStatus::Processed,
            queuedAt: null,
            startedAt: microtime(true),
        ));
    }

    expect($buffer->count())->toBe(3)
        ->and($buffer->drain()[0]->uuid)->toBe('3');
});

it('empties the buffer once flushed', function () {
    SucceedingJob::dispatch();

    expect(app(Yardmaster::class)->buffer()->isEmpty())->toBeTrue()
        ->and(DB::table('yard_runs')->count())->toBe(1);
});
