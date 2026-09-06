<?php

namespace Iocod\Yardmaster\Support;

use Iocod\Yardmaster\Entries\RunEntry;

/**
 * A mutable accumulator for one bucket's worth of entries.
 *
 * Collapsing a flush into one delta per group is what keeps the aggregate write
 * proportional to the number of distinct (queue, class, status) combinations
 * rather than to the number of jobs.
 */
final class BucketDelta
{
    public int $count = 0;

    public float $sumRuntime = 0.0;

    public ?float $minRuntime = null;

    public ?float $maxRuntime = null;

    public float $sumWait = 0.0;

    /** @var array<int, int> */
    public array $runtimeHistogram = [];

    /** @var array<int, int> */
    public array $waitHistogram = [];

    public function __construct(
        public readonly string $keyHash,
        public readonly int $periodStart,
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $jobClass,
        public readonly string $status,
        public readonly float $sampleRate = 1.0,
    ) {}

    public function add(RunEntry $entry): void
    {
        $this->count++;

        if ($entry->runtimeMs !== null) {
            $this->sumRuntime += $entry->runtimeMs;
            $this->minRuntime = $this->minRuntime === null
                ? $entry->runtimeMs
                : min($this->minRuntime, $entry->runtimeMs);
            $this->maxRuntime = $this->maxRuntime === null
                ? $entry->runtimeMs
                : max($this->maxRuntime, $entry->runtimeMs);
            $this->runtimeHistogram = Histogram::record($this->runtimeHistogram, $entry->runtimeMs);
        }

        if ($entry->waitMs !== null) {
            $this->sumWait += $entry->waitMs;
            $this->waitHistogram = Histogram::record($this->waitHistogram, $entry->waitMs);
        }
    }
}
