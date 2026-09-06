<?php

use Abhishek\Yardmaster\Repositories\MetricsRepository;
use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Illuminate\Support\Facades\DB;

it('stamps every recorded attempt with the rate it was sampled at', function () {
    foreach (range(1, 60) as $i) {
        SucceedingJob::dispatch("run {$i}");
    }

    $rates = DB::table('yard_buckets')->distinct()->pluck('sample_rate')->all();
    $recorded = DB::table('yard_runs')->count();

    // Sampling is random, so the count is not asserted exactly — only that some
    // work was dropped and that everything kept carries its rate.
    expect($rates)->toBe([0.5])
        ->and($recorded)->toBeLessThan(60)
        ->and($recorded)->toBeGreaterThan(5);
});

it('scales sampled counts back up and admits they are estimates', function () {
    foreach (range(1, 60) as $i) {
        SucceedingJob::dispatch("run {$i}");
    }

    $observed = DB::table('yard_runs')->count();
    $summary = app(MetricsRepository::class)
        ->summary(time() - 3600, time() + 60);

    // Under-reporting by the sample rate would make a sampled dashboard quietly
    // wrong about volume, which is worse than not sampling at all.
    expect($summary['observed'])->toBe($observed)
        ->and($summary['total'])->toBe((int) round($observed / 0.5))
        ->and($summary['approximate'])->toBeTrue();
});
