<?php

namespace Iocod\Yardmaster\Drivers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Iocod\Yardmaster\Values\PendingJob;
use Iocod\Yardmaster\Values\QueueDepth;

/**
 * The database driver: the only one that can do everything, exactly.
 *
 * The jobs table is ordinary SQL, so every capability is a query. This adapter
 * doubles as the reference implementation the shared contract suite is written
 * against.
 */
class DatabaseAdapter extends Adapter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected string $connection,
        protected array $config,
        protected DatabaseManager $db,
        ?Cache $cache = null,
        ?Dispatcher $events = null,
    ) {
        parent::__construct($connection, $config, $cache, $events);
    }

    public function driver(): string
    {
        return 'database';
    }

    protected function supportedCapabilities(): array
    {
        return [
            Capability::CountPending,
            Capability::CountDelayed,
            Capability::CountReserved,
            Capability::OldestJobAge,
            Capability::ListQueues,
            Capability::PeekPayloads,
            Capability::DeleteById,
            Capability::PromoteDelayed,
            Capability::PurgeQueue,
        ];
    }

    protected function performQueues(): array
    {
        return $this->table()
            ->select('queue')
            ->distinct()
            ->orderBy('queue')
            ->pluck('queue')
            ->map(static fn ($queue) => (string) $queue)
            ->all();
    }

    protected function performDepth(string $queue): QueueDepth
    {
        $now = $this->now();

        return new QueueDepth(
            connection: $this->connection,
            queue: $queue,
            pending: $this->scoped($queue)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->count(),
            delayed: $this->scoped($queue)
                ->whereNull('reserved_at')
                ->where('available_at', '>', $now)
                ->count(),
            reserved: $this->scoped($queue)->whereNotNull('reserved_at')->count(),
            approximate: false,
            sampledAt: microtime(true),
        );
    }

    protected function performOldestPendingAge(string $queue): ?int
    {
        $now = $this->now();

        $availableAt = $this->scoped($queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->min('available_at');

        return $availableAt === null ? null : max(0, $now - (int) $availableAt);
    }

    protected function performPeek(string $queue, int $limit): array
    {
        $now = $this->now();

        return $this->scoped($queue)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => PendingJob::fromPayload(
                id: (string) $row->id,
                queue: $queue,
                payload: $this->decode($row->payload),
                availableAt: (int) $row->available_at,
                createdAt: (int) $row->created_at,
                delayed: (int) $row->available_at > $now,
            ))
            ->all();
    }

    protected function performForget(string $queue, string $id): bool
    {
        return $this->scoped($queue)->where('id', $id)->delete() > 0;
    }

    protected function performPromote(string $queue, string $id): bool
    {
        $now = $this->now();

        // Two conditions, both load-bearing. Reserved jobs are excluded because
        // rewriting available_at on one would hand a second worker a job that
        // is already running. Already-available jobs are excluded so that
        // promotion means the same thing here as on every other driver, where
        // it is physically a move out of a delayed structure and cannot
        // succeed twice.
        return $this->scoped($queue)
            ->where('id', $id)
            ->whereNull('reserved_at')
            ->where('available_at', '>', $now)
            ->update(['available_at' => $now]) > 0;
    }

    protected function performPurge(string $queue): int
    {
        return $this->scoped($queue)->delete();
    }

    protected function scoped(string $queue): Builder
    {
        return $this->table()->where('queue', $queue);
    }

    protected function table(): Builder
    {
        return $this->databaseConnection()->table(
            is_string($this->config['table'] ?? null) ? $this->config['table'] : 'jobs'
        );
    }

    protected function databaseConnection(): ConnectionInterface
    {
        $name = $this->config['connection'] ?? null;

        return $this->db->connection(is_string($name) ? $name : null);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function decode(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    protected function now(): int
    {
        return time();
    }
}
