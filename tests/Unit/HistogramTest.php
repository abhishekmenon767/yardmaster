<?php

use Iocod\Yardmaster\Support\Histogram;

it('places durations in decade-tenth buckets', function () {
    expect(Histogram::bucketFor(0.4))->toBe(0)
        ->and(Histogram::bucketFor(1))->toBe(0)
        ->and(Histogram::bucketFor(10))->toBe(10)
        ->and(Histogram::bucketFor(100))->toBe(20)
        ->and(Histogram::bucketFor(1000))->toBe(30)
        ->and(Histogram::bucketFor(60_000))->toBe(47);
});

it('clamps absurd durations into the overflow bucket', function () {
    expect(Histogram::bucketFor(PHP_INT_MAX))->toBe(Histogram::MAX_BUCKET);
});

it('merges by addition, which is the property the rollups depend on', function () {
    $a = Histogram::record([], 12.0);
    $a = Histogram::record($a, 12.0);
    $b = Histogram::record([], 12.0);
    $b = Histogram::record($b, 900.0);

    $merged = Histogram::merge($a, $b);

    expect(Histogram::count($merged))->toBe(4)
        ->and($merged[Histogram::bucketFor(12.0)])->toBe(3)
        ->and($merged[Histogram::bucketFor(900.0)])->toBe(1);
});

it('estimates percentiles within one bucket width of the true value', function () {
    $values = range(1, 1000);

    $histogram = [];
    foreach ($values as $value) {
        $histogram = Histogram::record($histogram, (float) $value);
    }

    // A bucket spans 10^0.1 (~1.26x), so a ~26% relative error is the
    // structural bound. Anything wider means the interpolation is wrong.
    // Pairs, not an array keyed by float: PHP casts float keys to int, which
    // silently collapses this table to a single p0 entry.
    foreach ([[0.5, 500], [0.95, 950], [0.99, 990]] as [$p, $exact]) {
        $estimate = Histogram::percentile($histogram, $p);

        expect(abs($estimate - $exact) / $exact)->toBeLessThan(0.26);
    }
});

it('returns null for an empty histogram rather than a misleading zero', function () {
    expect(Histogram::percentile([], 0.95))->toBeNull();
});

it('survives an encode and decode round trip', function () {
    $histogram = Histogram::record(Histogram::record([], 5.0), 5000.0);

    expect(Histogram::decode(Histogram::encode($histogram)))->toBe($histogram)
        ->and(Histogram::decode(null))->toBe([]);
});
