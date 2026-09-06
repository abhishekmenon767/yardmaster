<?php

namespace Iocod\Yardmaster\Repositories;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Iocod\Yardmaster\Enums\Period;
use Iocod\Yardmaster\Support\Histogram;

/**
 * Reads the pre-aggregated buckets.
 *
 * Nothing here ever touches yard_runs. That is the whole point of the rollup:
 * the expensive table can be trimmed back to hours and every chart on the
 * dashboard still answers, because a percentile over any range is a column-wise
 * sum of histograms rather than a scan of rows.
 */
class MetricsRepository
{
    public function __construct(
        protected DatabaseManager $db,
        protected Config $config,
    ) {}

    /**
     * The coarsest bucket that still gives a readable number of points.
     *
     * Asking for a quarter at minute resolution would sum 130,000 rows to draw
     * 130,000 pixels of chart; asking for the last hour at day resolution would
     * draw one.
     */
    public function resolvePeriod(int $from, int $to): Period
    {
        $span = max(1, $to - $from);

        return match (true) {
            $span <= 6 * 3600 => Period::Minute,
            $span <= 45 * 86400 => Period::Hour,
            default => Period::Day,
        };
    }

    /**
     * Headline figures for a window: volume, failure rate and latency shape.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(int $from, int $to, array $filters = []): array
    {
        $period = $this->resolvePeriod($from, $to);

        $rows = $this->query($period, $from, $to, $filters)
            ->select('status', 'count', 'sample_rate', 'sum_runtime', 'min_runtime', 'max_runtime', 'sum_wait', 'runtime_hist', 'wait_hist')
            ->get();

        $byStatus = [];
        $runtime = [];
        $wait = [];
        $total = 0;
        $observed = 0;
        $sumRuntime = 0.0;
        $sampled = false;

        foreach ($rows as $row) {
            $row = (array) $row;
            $rate = $this->rate($row);
            $count = (int) $row['count'];

            // Scale sampled counts back up. Latency percentiles are unaffected:
            // a sample of a distribution has the same shape as the whole.
            $scaled = (int) round($count / $rate);
            $sampled = $sampled || $rate < 1.0;
            $status = (string) $row['status'];

            $observed += $count;
            $total += $scaled;
            $sumRuntime += (float) $row['sum_runtime'];
            $byStatus[$status] = ($byStatus[$status] ?? 0) + $scaled;

            $runtime[] = Histogram::decode($this->text($row['runtime_hist'] ?? null));
            $wait[] = Histogram::decode($this->text($row['wait_hist'] ?? null));
        }

        $runtime = $runtime === [] ? [] : Histogram::merge(...$runtime);
        $wait = $wait === [] ? [] : Histogram::merge(...$wait);

        $failed = ($byStatus['failed'] ?? 0) + ($byStatus['timed_out'] ?? 0);

        return [
            'from' => $from,
            'to' => $to,
            'period' => $period->value,
            'total' => $total,
            // True when any figure here was scaled up from a sample. The
            // dashboard prefixes approximate numbers with '~' rather than
            // presenting an estimate as a count.
            'approximate' => $sampled,
            'observed' => $observed,
            'by_status' => $byStatus,
            'failed' => $failed,
            'failure_rate' => $total > 0 ? round($failed / $total, 4) : 0.0,
            'throughput_per_minute' => round($total / max(1, ($to - $from) / 60), 2),
            'runtime_ms' => [
                'mean' => $observed > 0 ? round($sumRuntime / $observed, 2) : null,
                'p50' => $this->round(Histogram::percentile($runtime, 0.50)),
                'p95' => $this->round(Histogram::percentile($runtime, 0.95)),
                'p99' => $this->round(Histogram::percentile($runtime, 0.99)),
            ],
            'wait_ms' => [
                'p50' => $this->round(Histogram::percentile($wait, 0.50)),
                'p95' => $this->round(Histogram::percentile($wait, 0.95)),
                'p99' => $this->round(Histogram::percentile($wait, 0.99)),
            ],
        ];
    }

    /**
     * A time series of counts per status, one point per bucket.
     *
     * Empty buckets are filled in so a chart shows a gap in throughput as a
     * trough rather than closing over it with a straight line.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function series(int $from, int $to, array $filters = []): array
    {
        $period = $this->resolvePeriod($from, $to);
        $step = $period->seconds();

        $rows = $this->query($period, $from, $to, $filters)
            ->select('period_start', 'status', 'sample_rate')
            ->selectRaw('SUM(count) as total')
            ->groupBy('period_start', 'status', 'sample_rate')
            ->get();

        $points = [];

        for ($at = $period->floor($from); $at <= $to; $at += $step) {
            $points[$at] = ['at' => $at, 'total' => 0, 'processed' => 0, 'failed' => 0, 'released' => 0];
        }

        foreach ($rows as $row) {
            $row = (array) $row;
            $at = (int) $row['period_start'];

            if (! isset($points[$at])) {
                continue;
            }

            $count = (int) round((int) $row['total'] / $this->rate($row));
            $points[$at]['total'] += $count;

            $key = match ((string) $row['status']) {
                'processed' => 'processed',
                'released' => 'released',
                'failed', 'timed_out' => 'failed',
                default => null,
            };

            if ($key !== null) {
                $points[$at][$key] += $count;
            }
        }

        return array_values($points);
    }

    /**
     * The job classes worth looking at first, ranked by whatever hurts most.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function topJobClasses(int $from, int $to, array $filters = [], int $limit = 10): array
    {
        $period = $this->resolvePeriod($from, $to);

        $rows = $this->query($period, $from, $to, $filters)
            ->select('job_class', 'status', 'count', 'sample_rate', 'sum_runtime', 'runtime_hist')
            ->get();

        $classes = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $class = (string) $row['job_class'];

            $classes[$class] ??= [
                'job_class' => $class,
                'total' => 0,
                'observed' => 0,
                'failed' => 0,
                'sum_runtime' => 0.0,
                'histogram' => [],
            ];

            $rate = $this->rate($row);
            $count = (int) round((int) $row['count'] / $rate);
            $classes[$class]['total'] += $count;
            $classes[$class]['observed'] += (int) $row['count'];
            $classes[$class]['sum_runtime'] += (float) $row['sum_runtime'];

            if (in_array((string) $row['status'], ['failed', 'timed_out'], true)) {
                $classes[$class]['failed'] += $count;
            }

            $classes[$class]['histogram'] = Histogram::merge(
                $classes[$class]['histogram'],
                Histogram::decode($this->text($row['runtime_hist'] ?? null)),
            );
        }

        $classes = array_map(function (array $class) {
            return [
                'job_class' => $class['job_class'],
                'total' => $class['total'],
                'failed' => $class['failed'],
                'failure_rate' => $class['total'] > 0
                    ? round($class['failed'] / $class['total'], 4)
                    : 0.0,
                'mean_ms' => $class['observed'] > 0
                    ? round($class['sum_runtime'] / $class['observed'], 2)
                    : null,
                'p95_ms' => $this->round(Histogram::percentile($class['histogram'], 0.95)),
            ];
        }, $classes);

        usort($classes, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return array_slice($classes, 0, $limit);
    }

    /**
     * Every queue Yardmaster has ever seen run a job.
     *
     * This is deliberately answered from telemetry rather than from the drivers.
     * Plane A sees every queue on every connection, including ones a driver
     * cannot enumerate, and including queues that are empty right now but were
     * busy an hour ago.
     *
     * @return array<int, array{connection: string, queue: string}>
     */
    public function knownQueues(?int $since = null): array
    {
        $query = $this->buckets()
            ->where('bucket', Period::Hour->value)
            ->select('connection', 'queue')
            ->distinct();

        if ($since !== null) {
            $query->where('period_start', '>=', $since);
        }

        return $query->orderBy('connection')->orderBy('queue')->get()
            ->map(static fn ($row) => [
                'connection' => (string) ((array) $row)['connection'],
                'queue' => (string) ((array) $row)['queue'],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function query(Period $period, int $from, int $to, array $filters): Builder
    {
        $query = $this->buckets()
            ->where('bucket', $period->value)
            ->where('period_start', '>=', $period->floor($from))
            ->where('period_start', '<=', $to);

        foreach (['connection', 'queue', 'job_class', 'status'] as $column) {
            if (($value = $filters[$column] ?? null) !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    protected function buckets(): Builder
    {
        $connection = $this->config->get('yardmaster.storage.connection');
        $table = $this->config->get('yardmaster.storage.buckets_table', 'yard_buckets');

        return $this->db->connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'yard_buckets');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function rate(array $row): float
    {
        $rate = (float) ($row['sample_rate'] ?? 1.0);

        return $rate > 0.0 ? $rate : 1.0;
    }

    protected function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    protected function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
