<?php

namespace Iocod\Yardmaster\Support;

/**
 * A log-scale latency histogram.
 *
 * Bucket i covers [10^(i/10), 10^((i+1)/10)) milliseconds, giving ~26% relative
 * width per bucket and covering 1ms to ~16 minutes in 61 buckets. Histograms
 * are stored sparsely as {bucket => count} and merge by addition, so the
 * percentile for any time range is a column-wise sum of its buckets rather than
 * a scan of the underlying rows. That property is the whole point: it is what
 * keeps a p95 over a week affordable once the runs table has been trimmed away.
 */
final class Histogram
{
    /** Buckets 0..60 cover 1ms through 10^6.1 ms (~19 minutes). */
    public const MAX_BUCKET = 60;

    /** Anything at or above the top bucket's floor lands in the overflow bucket. */
    public const PRECISION = 10;

    /**
     * Resolve the bucket index for a duration in milliseconds.
     */
    public static function bucketFor(float $milliseconds): int
    {
        if ($milliseconds < 1.0) {
            return 0;
        }

        $index = (int) floor(log10($milliseconds) * self::PRECISION);

        return max(0, min(self::MAX_BUCKET, $index));
    }

    /**
     * The inclusive lower bound of a bucket, in milliseconds.
     */
    public static function lowerBound(int $bucket): float
    {
        return $bucket <= 0 ? 0.0 : 10 ** ($bucket / self::PRECISION);
    }

    /**
     * The exclusive upper bound of a bucket, in milliseconds.
     */
    public static function upperBound(int $bucket): float
    {
        return 10 ** (($bucket + 1) / self::PRECISION);
    }

    /**
     * Record a single observation into a sparse histogram.
     *
     * @param  array<int, int>  $histogram
     * @return array<int, int>
     */
    public static function record(array $histogram, float $milliseconds, int $count = 1): array
    {
        $bucket = self::bucketFor($milliseconds);

        $histogram[$bucket] = ($histogram[$bucket] ?? 0) + $count;

        return $histogram;
    }

    /**
     * Merge any number of sparse histograms into one.
     *
     * @param  array<int, int>  ...$histograms
     * @return array<int, int>
     */
    public static function merge(array ...$histograms): array
    {
        $merged = [];

        foreach ($histograms as $histogram) {
            foreach ($histogram as $bucket => $count) {
                $bucket = (int) $bucket;
                $merged[$bucket] = ($merged[$bucket] ?? 0) + (int) $count;
            }
        }

        ksort($merged);

        return $merged;
    }

    /**
     * Total observations held by a histogram.
     *
     * @param  array<int, int>  $histogram
     */
    public static function count(array $histogram): int
    {
        return (int) array_sum($histogram);
    }

    /**
     * Estimate a percentile, in milliseconds.
     *
     * Within the containing bucket the position is interpolated linearly, which
     * bounds the error by the bucket width rather than leaving it unbounded at
     * the bucket edge.
     *
     * @param  array<int, int>  $histogram
     * @param  float  $percentile  Between 0 and 1 — 0.95 for p95.
     */
    public static function percentile(array $histogram, float $percentile): ?float
    {
        $total = self::count($histogram);

        if ($total === 0) {
            return null;
        }

        $percentile = max(0.0, min(1.0, $percentile));
        $target = $percentile * $total;

        $buckets = [];
        foreach ($histogram as $bucket => $count) {
            $buckets[(int) $bucket] = (int) $count;
        }
        ksort($buckets);

        $cumulative = 0;

        foreach ($buckets as $bucket => $count) {
            $previous = $cumulative;
            $cumulative += $count;

            if ($cumulative < $target) {
                continue;
            }

            $lower = self::lowerBound($bucket);
            $upper = self::upperBound($bucket);

            // Where the target falls inside this bucket's own observations.
            $position = $count > 0 ? ($target - $previous) / $count : 1.0;

            return $lower + (($upper - $lower) * min(1.0, max(0.0, $position)));
        }

        $last = array_key_last($buckets);

        return $last === null ? null : self::upperBound($last);
    }

    /**
     * Encode a sparse histogram for storage.
     *
     * @param  array<int, int>  $histogram
     */
    public static function encode(array $histogram): string
    {
        ksort($histogram);

        return json_encode($histogram, JSON_THROW_ON_ERROR) ?: '{}';
    }

    /**
     * Decode a stored histogram back into a sparse array.
     *
     * @return array<int, int>
     */
    public static function decode(?string $encoded): array
    {
        if ($encoded === null || $encoded === '') {
            return [];
        }

        $decoded = json_decode($encoded, true);

        if (! is_array($decoded)) {
            return [];
        }

        $histogram = [];
        foreach ($decoded as $bucket => $count) {
            $histogram[Cast::int($bucket)] = Cast::int($count);
        }

        return $histogram;
    }
}
