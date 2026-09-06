<?php

use Abhishek\Yardmaster\Enums\Period;
use Abhishek\Yardmaster\Support\Histogram;
use Abhishek\Yardmaster\Tests\Fixtures\FailingJob;
use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Illuminate\Support\Facades\DB;

it('rolls every attempt into minute, hour and day buckets', function () {
    SucceedingJob::dispatch();

    $buckets = DB::table('yard_buckets')->pluck('bucket')->sort()->values()->all();

    expect($buckets)->toBe(['day', 'hour', 'minute']);
});

it('merges repeat attempts into one bucket row per group', function () {
    foreach (range(1, 5) as $i) {
        SucceedingJob::dispatch("run {$i}");
    }

    $bucket = DB::table('yard_buckets')->where('bucket', 'minute')->first();

    expect(DB::table('yard_buckets')->where('bucket', 'minute')->count())->toBe(1)
        ->and((int) $bucket->count)->toBe(5)
        ->and(Histogram::count(Histogram::decode($bucket->runtime_hist)))->toBe(5)
        ->and((float) $bucket->min_runtime)->toBeLessThanOrEqual((float) $bucket->max_runtime)
        ->and((float) $bucket->sum_runtime)->toBeGreaterThan(0.0);
});

it('keeps successes and failures in separate buckets', function () {
    SucceedingJob::dispatch();
    FailingJob::dispatch()->onConnection('database');
    $this->work('database');

    $statuses = DB::table('yard_buckets')
        ->where('bucket', 'minute')
        ->pluck('status')
        ->sort()
        ->values()
        ->all();

    expect($statuses)->toBe(['failed', 'processed']);
});

it('floors each bucket to the start of its period', function () {
    SucceedingJob::dispatch();

    $minute = DB::table('yard_buckets')->where('bucket', 'minute')->value('period_start');
    $day = DB::table('yard_buckets')->where('bucket', 'day')->value('period_start');

    expect((int) $minute % Period::Minute->seconds())->toBe(0)
        ->and((int) $day % Period::Day->seconds())->toBe(0);
});

it('answers a percentile from buckets alone, after the runs are gone', function () {
    foreach (range(1, 10) as $i) {
        SucceedingJob::dispatch("run {$i}");
    }

    // The whole point of the rollup: delete the expensive half and the
    // dashboard can still answer.
    DB::table('yard_runs')->delete();

    $histogram = Histogram::merge(...DB::table('yard_buckets')
        ->where('bucket', 'minute')
        ->pluck('runtime_hist')
        ->map(fn ($h) => Histogram::decode($h))
        ->all());

    expect(Histogram::count($histogram))->toBe(10)
        ->and(Histogram::percentile($histogram, 0.95))->toBeGreaterThan(0.0);
});
