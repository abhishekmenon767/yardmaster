<?php

namespace Iocod\Yardmaster\Ingest;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Iocod\Yardmaster\Contracts\Ingest;
use Iocod\Yardmaster\Entries\RunEntry;
use Iocod\Yardmaster\Enums\Period;
use Iocod\Yardmaster\Support\BucketDelta;
use Iocod\Yardmaster\Support\Histogram;

/**
 * Writes buffered entries straight to the database.
 *
 * Two writes happen per flush: the raw attempt rows, and a read-merge-write of
 * the pre-aggregated buckets they roll into. The aggregate write is the one
 * that matters — it is what lets the runs table be trimmed to hours while the
 * dashboard still answers questions about last quarter.
 */
class DatabaseIngest implements Ingest
{
    public function __construct(
        protected DatabaseManager $db,
        protected Config $config,
    ) {}

    public function ingest(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->writeRuns($entries);
        $this->writeBuckets($entries);
    }

    /**
     * @param  array<int, RunEntry>  $entries
     */
    protected function writeRuns(array $entries): void
    {
        $rows = array_map(static fn (RunEntry $entry) => $entry->toDatabaseRow(), $entries);

        foreach (array_chunk($rows, 250) as $chunk) {
            $this->connection()->table($this->runsTable())->insert($chunk);
        }
    }

    /**
     * Roll entries into minute, hour and day buckets.
     *
     * @param  array<int, RunEntry>  $entries
     */
    protected function writeBuckets(array $entries): void
    {
        foreach (Period::cases() as $period) {
            foreach ($this->aggregate($entries, $period) as $delta) {
                $this->applyDelta($period, $delta);
            }
        }
    }

    /**
     * Collapse entries into one delta per (period, connection, queue, class,
     * status) group, so a flush of 250 rows becomes a handful of writes.
     *
     * @param  array<int, RunEntry>  $entries
     * @return array<string, BucketDelta>
     */
    protected function aggregate(array $entries, Period $period): array
    {
        $deltas = [];

        foreach ($entries as $entry) {
            $periodStart = $period->floor($entry->startedAt);

            $key = $this->keyHash(
                $periodStart,
                $entry->connection,
                $entry->queue,
                $entry->jobClass,
                $entry->status->value,
                (string) $entry->sampleRate,
            );

            $deltas[$key] ??= new BucketDelta(
                keyHash: $key,
                periodStart: $periodStart,
                connection: $entry->connection,
                queue: $entry->queue,
                jobClass: $entry->jobClass,
                status: $entry->status->value,
                sampleRate: $entry->sampleRate,
            );

            $deltas[$key]->add($entry);
        }

        return $deltas;
    }

    /**
     * Merge one delta into its stored bucket.
     *
     * Read-merge-write under a row lock, because the histogram column cannot be
     * incremented in SQL. Contention is bounded by the fact that a flush writes
     * one row per group, not one per job.
     */
    protected function applyDelta(Period $period, BucketDelta $delta): void
    {
        $connection = $this->connection();
        $table = $this->bucketsTable();

        $connection->transaction(function () use ($connection, $table, $period, $delta): void {
            $query = fn () => $connection->table($table)
                ->where('bucket', $period->value)
                ->where('period_start', $delta->periodStart)
                ->where('key_hash', $delta->keyHash);

            $existing = $query()->lockForUpdate()->first();

            if ($existing === null) {
                $connection->table($table)->insert([
                    'bucket' => $period->value,
                    'period_start' => $delta->periodStart,
                    'key_hash' => $delta->keyHash,
                    'connection' => $delta->connection,
                    'queue' => $delta->queue,
                    'job_class' => $delta->jobClass,
                    'status' => $delta->status,
                    'sample_rate' => $delta->sampleRate,
                    'count' => $delta->count,
                    'sum_runtime' => $delta->sumRuntime,
                    'min_runtime' => $delta->minRuntime,
                    'max_runtime' => $delta->maxRuntime,
                    'sum_wait' => $delta->sumWait,
                    'runtime_hist' => Histogram::encode($delta->runtimeHistogram),
                    'wait_hist' => Histogram::encode($delta->waitHistogram),
                ]);

                return;
            }

            $query()->update([
                'count' => (int) $existing->count + $delta->count,
                'sum_runtime' => (float) $existing->sum_runtime + $delta->sumRuntime,
                'min_runtime' => $this->least(
                    $existing->min_runtime === null ? null : (float) $existing->min_runtime,
                    $delta->minRuntime,
                ),
                'max_runtime' => $this->greatest(
                    $existing->max_runtime === null ? null : (float) $existing->max_runtime,
                    $delta->maxRuntime,
                ),
                'sum_wait' => (float) $existing->sum_wait + $delta->sumWait,
                'runtime_hist' => Histogram::encode(Histogram::merge(
                    Histogram::decode($existing->runtime_hist),
                    $delta->runtimeHistogram,
                )),
                'wait_hist' => Histogram::encode(Histogram::merge(
                    Histogram::decode($existing->wait_hist),
                    $delta->waitHistogram,
                )),
            ]);
        });
    }

    protected function least(?float $a, ?float $b): ?float
    {
        if ($a === null) {
            return $b;
        }

        return $b === null ? $a : min($a, $b);
    }

    protected function greatest(?float $a, ?float $b): ?float
    {
        if ($a === null) {
            return $b;
        }

        return $b === null ? $a : max($a, $b);
    }

    protected function keyHash(int $periodStart, string ...$parts): string
    {
        return md5($periodStart.'|'.implode('|', $parts));
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->config->get('yardmaster.storage.connection'));
    }

    protected function runsTable(): string
    {
        return $this->config->get('yardmaster.storage.runs_table', 'yard_runs');
    }

    protected function bucketsTable(): string
    {
        return $this->config->get('yardmaster.storage.buckets_table', 'yard_buckets');
    }
}
