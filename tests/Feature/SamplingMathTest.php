<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Enums\Period;
use Iocod\Yardmaster\Repositories\MetricsRepository;
use Iocod\Yardmaster\Support\Histogram;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;

/**
 * The scaling arithmetic, pinned deterministically rather than through a
 * random sample.
 */
function bucket(array $attributes = []): void
{
    DB::table('yard_buckets')->insert(array_merge([
        'bucket' => Period::Minute->value,
        'period_start' => Period::Minute->floor(time()),
        'key_hash' => md5(uniqid('', true)),
        'connection' => 'database',
        'queue' => 'default',
        'job_class' => 'App\\Jobs\\Example',
        'status' => 'processed',
        'sample_rate' => 1.0,
        'count' => 1,
        'sum_runtime' => 100.0,
        'min_runtime' => 100.0,
        'max_runtime' => 100.0,
        'sum_wait' => 0.0,
        'runtime_hist' => Histogram::encode(Histogram::record([], 100.0)),
        'wait_hist' => Histogram::encode([]),
    ], $attributes));
}

it('scales a sampled bucket by the inverse of its rate', function () {
    bucket(['count' => 10, 'sample_rate' => 0.1]);

    $summary = app(MetricsRepository::class)->summary(time() - 600, time() + 60);

    expect($summary['total'])->toBe(100)
        ->and($summary['observed'])->toBe(10)
        ->and($summary['approximate'])->toBeTrue();
});

it('leaves an unsampled bucket exactly as recorded', function () {
    bucket(['count' => 7]);

    $summary = app(MetricsRepository::class)->summary(time() - 600, time() + 60);

    expect($summary['total'])->toBe(7)
        ->and($summary['approximate'])->toBeFalse();
});

it('never mixes an estimate and an exact count into one bucket row', function () {
    SucceedingJob::dispatch();
    bucket(['count' => 5, 'sample_rate' => 0.25]);

    // Two rows, not one: adding a scaled estimate to a real count would give a
    // third kind of number that is neither.
    $rows = DB::table('yard_buckets')
        ->where('bucket', Period::Minute->value)
        ->orderBy('sample_rate')
        ->pluck('sample_rate')
        ->all();

    expect($rows)->toBe([0.25, 1.0]);
});

it('takes the mean from what was observed, not from the scaled estimate', function () {
    bucket(['count' => 10, 'sample_rate' => 0.1, 'sum_runtime' => 1000.0]);

    $summary = app(MetricsRepository::class)->summary(time() - 600, time() + 60);

    // 1000ms over 10 observed attempts is 100ms; dividing by the scaled 100
    // would report 10ms and understate every job in the system tenfold.
    expect($summary['runtime_ms']['mean'])->toBe(100.0);
});
