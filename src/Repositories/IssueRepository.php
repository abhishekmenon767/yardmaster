<?php

namespace Iocod\Yardmaster\Repositories;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Iocod\Yardmaster\Support\Cast;

/**
 * Reads and updates grouped failures.
 */
class IssueRepository
{
    public function __construct(
        protected DatabaseManager $db,
        protected Config $config,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);

        $query = $this->issues();

        // Open by default: an operator opening this page wants what is wrong
        // now, not a history of everything ever dealt with.
        $status = $filters['status'] ?? 'open';

        if ($status !== 'all' && is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $term = '%'.Cast::string($search).'%';
            $query->where(function (Builder $inner) use ($term) {
                $inner->where('exception_class', 'like', $term)
                    ->orWhere('normalised_message', 'like', $term)
                    ->orWhere('job_class', 'like', $term)
                    ->orWhere('frame', 'like', $term);
            });
        }

        if (($jobClass = $filters['job_class'] ?? null) !== null && $jobClass !== '') {
            $query->where('job_class', $jobClass);
        }

        $total = (clone $query)->count();

        return [
            'data' => $query->orderByDesc('last_seen')
                ->forPage($page, $perPage)
                ->get()
                ->map(fn ($row) => $this->present((array) $row))
                ->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $fingerprint): ?array
    {
        $row = $this->issues()->where('fingerprint', $fingerprint)->first();

        return $row === null ? null : $this->present((array) $row);
    }

    public function setStatus(string $fingerprint, string $status, ?string $actor = null): bool
    {
        if (! in_array($status, ['open', 'ignored', 'resolved'], true)) {
            return false;
        }

        return $this->issues()->where('fingerprint', $fingerprint)->update([
            'status' => $status,
            'resolved_at' => $status === 'resolved' ? microtime(true) : null,
            'resolved_by' => $status === 'resolved' ? $actor : null,
        ]) > 0;
    }

    /**
     * The job ids that make up an issue, newest first.
     *
     * @return array<int, string>
     */
    public function jobUuids(string $fingerprint, int $limit = 1000): array
    {
        $table = $this->config->get('yardmaster.storage.runs_table', 'yard_runs');

        return $this->connection()
            ->table(is_string($table) ? $table : 'yard_runs')
            ->where('fingerprint', $fingerprint)
            ->whereIn('status', ['failed', 'timed_out'])
            ->whereNotNull('job_uuid')
            ->orderByDesc('started_at')
            ->limit(max(1, $limit))
            ->pluck('job_uuid')
            ->map(static fn ($uuid) => Cast::string($uuid))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function present(array $row): array
    {
        return [
            'fingerprint' => Cast::string($row['fingerprint']),
            'exception_class' => Cast::string($row['exception_class']),
            'message' => Cast::string($row['normalised_message']),
            'sample_message' => $row['sample_message'] === null ? null : Cast::string($row['sample_message']),
            'frame' => $row['frame'] === null ? null : Cast::string($row['frame']),
            'job_class' => Cast::string($row['job_class']),
            'occurrences' => Cast::int($row['occurrences']),
            'first_seen' => Cast::float($row['first_seen']),
            'last_seen' => Cast::float($row['last_seen']),
            'status' => Cast::string($row['status']),
            'sample_run_uuid' => $row['sample_run_uuid'] === null ? null : Cast::string($row['sample_run_uuid']),
        ];
    }

    protected function issues(): Builder
    {
        $table = $this->config->get('yardmaster.storage.issues_table', 'yard_issues');

        return $this->connection()->table(is_string($table) ? $table : 'yard_issues');
    }

    protected function connection(): ConnectionInterface
    {
        $name = $this->config->get('yardmaster.storage.connection');

        return $this->db->connection(is_string($name) ? $name : null);
    }
}
