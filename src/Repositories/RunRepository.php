<?php

namespace Iocod\Yardmaster\Repositories;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Iocod\Yardmaster\Support\Cast;

/**
 * Reads the per-attempt run log.
 *
 * The hot half of storage: recent, detailed, and trimmed back to hours. Every
 * query here leads with a filtered dimension and ends at the time column, which
 * is the shape the indexes were built for.
 */
class RunRepository
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

        $query = $this->filtered($filters);
        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($row) => $this->present((array) $row))
            ->all();

        return [
            'data' => $rows,
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
    public function find(string $uuid): ?array
    {
        $row = $this->runs()->where('uuid', $uuid)->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        $run = $this->present($row, withPayload: true);

        // A job's history is its attempts, so the detail view shows the whole
        // chain rather than the single row that was clicked.
        $jobUuid = $this->stringOrNull($row['job_uuid'] ?? null);

        $run['attempts'] = $jobUuid === null ? [] : $this->runs()
            ->where('job_uuid', $jobUuid)
            ->orderBy('started_at')
            ->get()
            ->map(fn ($attempt) => $this->present((array) $attempt))
            ->all();

        $run['children'] = $jobUuid === null ? [] : $this->runs()
            ->where('parent_uuid', $jobUuid)
            ->orderBy('started_at')
            ->limit(100)
            ->get()
            ->map(fn ($child) => $this->present((array) $child))
            ->all();

        return $run;
    }

    /**
     * Distinct values for a filterable column, for populating the UI's filters.
     *
     * @return array<int, string>
     */
    public function distinct(string $column, int $limit = 200): array
    {
        if (! in_array($column, ['connection', 'queue', 'job_class', 'status'], true)) {
            return [];
        }

        return $this->runs()
            ->select($column)
            ->distinct()
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column)
            ->map(static fn ($value) => Cast::string($value))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function filtered(array $filters): Builder
    {
        $query = $this->runs();

        foreach (['connection', 'queue', 'job_class', 'status', 'batch_id'] as $column) {
            if (($value = $filters[$column] ?? null) !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        if (($failed = $filters['failed'] ?? null)) {
            $query->whereIn('status', ['failed', 'timed_out']);
        }

        if (($from = $filters['from'] ?? null) !== null) {
            $query->where('started_at', '>=', Cast::float($from));
        }

        if (($to = $filters['to'] ?? null) !== null) {
            $query->where('started_at', '<=', Cast::float($to));
        }

        if (($slower = $filters['slower_than'] ?? null) !== null) {
            $query->where('runtime_ms', '>=', Cast::float($slower));
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $term = '%'.Cast::string($search).'%';

            $query->where(function (Builder $inner) use ($term) {
                $inner->where('job_class', 'like', $term)
                    ->orWhere('exception_message', 'like', $term)
                    ->orWhere('exception_class', 'like', $term)
                    ->orWhere('job_uuid', 'like', $term);
            });
        }

        if (($tag = $filters['tag'] ?? null) !== null && $tag !== '') {
            // Tags are a small JSON array; a LIKE against the encoded form is
            // portable across MySQL, Postgres and SQLite, which JSON operators
            // are not.
            $query->where('tags', 'like', '%'.json_encode(Cast::string($tag)).'%');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Query builder rows are stdClass with no declared shape, so they are read
     * as the associative arrays they really are.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function present(array $row, bool $withPayload = false): array
    {
        $run = [
            'uuid' => Cast::string($row['uuid'] ?? ''),
            'job_uuid' => $this->stringOrNull($row['job_uuid'] ?? null),
            'job_class' => Cast::string($row['job_class'] ?? ''),
            'connection' => Cast::string($row['connection'] ?? ''),
            'queue' => Cast::string($row['queue'] ?? ''),
            'attempt' => Cast::int($row['attempt'] ?? 1),
            'status' => Cast::string($row['status'] ?? ''),
            'queued_at' => $this->floatOrNull($row['queued_at'] ?? null),
            'started_at' => Cast::float($row['started_at'] ?? 0),
            'finished_at' => $this->floatOrNull($row['finished_at'] ?? null),
            'wait_ms' => $this->floatOrNull($row['wait_ms'] ?? null),
            'runtime_ms' => $this->floatOrNull($row['runtime_ms'] ?? null),
            'peak_memory_kb' => isset($row['peak_memory_kb']) ? Cast::int($row['peak_memory_kb']) : null,
            'batch_id' => $this->stringOrNull($row['batch_id'] ?? null),
            'parent_uuid' => $this->stringOrNull($row['parent_uuid'] ?? null),
            'tags' => $this->decode($row['tags'] ?? null),
            'exception_class' => $this->stringOrNull($row['exception_class'] ?? null),
            'exception_message' => $this->stringOrNull($row['exception_message'] ?? null),
        ];

        if ($withPayload) {
            $run['payload'] = $this->decode($row['payload'] ?? null);
        }

        return $run;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : Cast::string($value);
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    protected function decode(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function runs(): Builder
    {
        $connection = $this->config->get('yardmaster.storage.connection');
        $table = $this->config->get('yardmaster.storage.runs_table', 'yard_runs');

        return $this->db->connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'yard_runs');
    }
}
