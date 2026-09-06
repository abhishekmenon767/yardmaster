<?php

namespace Iocod\Yardmaster\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Iocod\Yardmaster\Enums\Period;
use Iocod\Yardmaster\Support\Cast;

/**
 * Enforces retention.
 *
 * Runs and buckets age out on very different clocks by design: the per-attempt
 * rows are expensive and short-lived, the aggregates are cheap and kept for
 * months. Schedule this every fifteen minutes.
 */
class TrimCommand extends Command
{
    protected $signature = 'yard:trim {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Trim Yardmaster telemetry past its retention window';

    public function handle(DatabaseManager $db): int
    {
        $name = config('yardmaster.storage.connection');
        $connection = $db->connection(is_string($name) ? $name : null);

        $runs = Cast::string(config('yardmaster.storage.runs_table'), 'yard_runs');
        $buckets = Cast::string(config('yardmaster.storage.buckets_table'), 'yard_buckets');
        $dry = (bool) $this->option('dry-run');

        $runsBefore = $this->cutoff(Cast::string(config('yardmaster.retention.runs'), '48 hours'));
        $payloadsBefore = $this->cutoff(Cast::string(config('yardmaster.retention.payloads'), '6 hours'));

        $expiredRuns = $connection->table($runs)->where('started_at', '<', $runsBefore);
        $this->report('runs', $expiredRuns->count(), $dry);
        if (! $dry) {
            $expiredRuns->delete();
        }

        // Payloads are dropped well before the rows that carry them: the row is
        // useful for weeks of trend, the payload is a liability after hours.
        $stalePayloads = $connection->table($runs)
            ->where('started_at', '<', $payloadsBefore)
            ->whereNotNull('payload');
        $this->report('payloads', $stalePayloads->count(), $dry);
        if (! $dry) {
            $stalePayloads->update(['payload' => null]);
        }

        $workers = $connection->table(Cast::string(config('yardmaster.storage.workers_table'), 'yard_workers'))
            ->where('last_seen', '<', microtime(true) - $this->seconds(
                config('yardmaster.retention.workers', '10 minutes')
            ));
        $this->report('dead workers', $workers->count(), $dry);
        if (! $dry) {
            // A killed process never gets to remove its own row.
            $workers->delete();
        }

        foreach (Period::cases() as $period) {
            $retention = Cast::string(config("yardmaster.retention.buckets.{$period->value}"));

            if ($retention === '') {
                continue;
            }

            $expired = $connection->table($buckets)
                ->where('bucket', $period->value)
                ->where('period_start', '<', $this->cutoff($retention));

            $this->report("{$period->value} buckets", $expired->count(), $dry);

            if (! $dry) {
                $expired->delete();
            }
        }

        return self::SUCCESS;
    }

    protected function cutoff(string $interval): int
    {
        return (int) strtotime('-'.ltrim($interval, '-'));
    }

    protected function seconds(mixed $interval): int
    {
        return max(60, time() - $this->cutoff(is_string($interval) ? $interval : '10 minutes'));
    }

    protected function report(string $label, int $count, bool $dry): void
    {
        $this->line(sprintf(
            '  %s %s %s',
            str_pad($label, 16),
            str_pad((string) $count, 9, ' ', STR_PAD_LEFT),
            $dry ? 'would be trimmed' : 'trimmed',
        ));
    }
}
