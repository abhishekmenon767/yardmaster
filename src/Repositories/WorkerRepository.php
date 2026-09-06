<?php

namespace Iocod\Yardmaster\Repositories;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Iocod\Yardmaster\Recorders\WorkerHeartbeat;
use Iocod\Yardmaster\Support\Cast;

class WorkerRepository
{
    public function __construct(
        protected DatabaseManager $db,
        protected Config $config,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $staleAfter = Cast::int($this->config->get(
            'yardmaster.recorders.'.WorkerHeartbeat::class.'.stale_after',
            30
        ), 30);

        $now = microtime(true);

        return $this->table()->orderBy('host')->orderBy('pid')->get()
            ->map(function ($row) use ($now, $staleAfter) {
                $row = (array) $row;
                $silentFor = $now - (float) $row['last_seen'];

                return [
                    'name' => (string) $row['name'],
                    'host' => (string) $row['host'],
                    'pid' => (int) $row['pid'],
                    'connection' => (string) $row['connection'],
                    'queues' => array_values(array_filter(explode(',', (string) $row['queues']))),
                    // A worker that has stopped reporting is reported as stale
                    // rather than dropped: silence is the symptom worth seeing.
                    'status' => $silentFor > $staleAfter ? 'stale' : (string) $row['status'],
                    'current_job' => $row['current_job'] === null ? null : (string) $row['current_job'],
                    'current_job_seconds' => $row['current_job_started_at'] === null
                        ? null
                        : round($now - (float) $row['current_job_started_at'], 1),
                    'memory_kb' => $row['memory_kb'] === null ? null : (int) $row['memory_kb'],
                    'processed' => (int) $row['processed'],
                    'failed' => (int) $row['failed'],
                    'uptime_seconds' => (int) round($now - (float) $row['started_at']),
                    'silent_for_seconds' => round($silentFor, 1),
                ];
            })
            ->all();
    }

    /**
     * Forget workers that have been silent long enough that they are certainly
     * gone — a killed process never gets to remove its own row.
     */
    public function prune(int $olderThanSeconds = 3600): int
    {
        return $this->table()->where('last_seen', '<', microtime(true) - $olderThanSeconds)->delete();
    }

    protected function table(): Builder
    {
        $connection = $this->config->get('yardmaster.storage.connection');
        $table = $this->config->get('yardmaster.storage.workers_table', 'yard_workers');

        return $this->db->connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'yard_workers');
    }
}
