<?php

use Iocod\Yardmaster\Http\StreamPayload;
use Iocod\Yardmaster\Tests\Fixtures\FailingJob;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;

beforeEach(fn () => $this->grantDashboard());

it('answers throughput, failure rate and percentiles from the rollups', function () {
    foreach (range(1, 4) as $i) {
        SucceedingJob::dispatch("run {$i}");
    }
    FailingJob::dispatch()->onConnection('database');
    $this->work('database');

    $summary = $this->getJson('yardmaster/api/v1/metrics')->assertOk()->json('summary');

    expect($summary['total'])->toBe(5)
        ->and($summary['failed'])->toBe(1)
        ->and($summary['failure_rate'])->toBe(0.2)
        ->and($summary['by_status']['processed'])->toBe(4)
        ->and($summary['runtime_ms']['p95'])->toBeGreaterThan(0)
        ->and($summary['period'])->toBe('minute');
});

it('returns a gap-filled series so a stall reads as a trough', function () {
    SucceedingJob::dispatch();

    $to = time();
    $from = $to - 600;

    $series = $this->getJson("yardmaster/api/v1/metrics?from={$from}&to={$to}")->json('series');

    // Eleven minute buckets across a ten-minute window, all present.
    expect($series)->toHaveCount(11)
        ->and(collect($series)->sum('total'))->toBe(1)
        ->and(collect($series)->every(fn ($point) => array_key_exists('failed', $point)))->toBeTrue();
});

it('coarsens the bucket as the window widens', function () {
    $to = time();

    $hourly = $this->getJson('yardmaster/api/v1/metrics?from='.($to - 86400)."&to={$to}")->json('summary.period');
    $daily = $this->getJson('yardmaster/api/v1/metrics?from='.($to - 200 * 86400)."&to={$to}")->json('summary.period');

    expect($hourly)->toBe('hour')
        ->and($daily)->toBe('day');
});

it('ranks the job classes worth looking at first', function () {
    SucceedingJob::dispatch();
    SucceedingJob::dispatch();
    FailingJob::dispatch()->onConnection('database');
    $this->work('database');

    $top = $this->getJson('yardmaster/api/v1/metrics')->json('top_job_classes');

    expect($top[0]['job_class'])->toBe(SucceedingJob::class)
        ->and($top[0]['total'])->toBe(2)
        ->and(collect($top)->firstWhere('job_class', FailingJob::class)['failure_rate'])->toBe(1.0);
});

it('builds a live stream tick with depths and recent throughput', function () {
    SucceedingJob::dispatch();

    $tick = app(StreamPayload::class)->build();

    expect($tick)->toHaveKeys(['at', 'queues', 'recent'])
        ->and($tick['recent']['total'])->toBe(1)
        ->and($tick['recent']['failure_rate'])->toBe(0.0);
});

it('streams as server-sent events rather than a buffered response', function () {
    $response = $this->get('yardmaster/api/v1/stream?duration=5&interval=1');

    expect($response->headers->get('Content-Type'))->toContain('text/event-stream')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');
});
