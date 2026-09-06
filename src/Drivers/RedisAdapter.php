<?php

namespace Abhishek\Yardmaster\Drivers;

use Abhishek\Yardmaster\Concerns\CallsRedis;
use Abhishek\Yardmaster\Support\Cast;
use Abhishek\Yardmaster\Values\PendingJob;
use Abhishek\Yardmaster\Values\QueueDepth;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * The Redis driver, read through Laravel's own key layout.
 *
 * Laravel keeps ready jobs in a list at `queues:{name}` (pushed with RPUSH,
 * popped with LPOP, so index 0 is the oldest), and delayed and reserved jobs in
 * sorted sets at `:delayed` and `:reserved` scored by their availability time.
 * Everything here is read-only against those structures — this adapter never
 * pops, so it can never race a worker for a job.
 */
class RedisAdapter extends Adapter
{
    use CallsRedis;

    /**
     * Redis has no per-job handle, so a job is addressed by its payload uuid
     * and located by scanning. This caps how far that scan will go before
     * giving up rather than stalling the dashboard on a million-job backlog.
     */
    protected const SCAN_CHUNK = 500;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected string $connection,
        protected array $config,
        protected RedisFactory $redis,
        ?Cache $cache = null,
        ?Dispatcher $events = null,
        protected string $keyPrefix = '',
        protected int $maxScan = 10000,
    ) {
        parent::__construct($connection, $config, $cache, $events);
    }

    public function driver(): string
    {
        return 'redis';
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
        $found = [];
        $cursor = 0;

        // The client prefixes ordinary commands but not SCAN's MATCH pattern,
        // and returns keys with the prefix still attached. Both ends are
        // handled explicitly here rather than trusted to the client.
        do {
            $result = $this->call('scan', [$cursor, [
                'match' => $this->keyPrefix.'queues:*',
                'count' => 100,
            ]]);

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            [$cursor, $keys] = $result;

            foreach ((array) $keys as $key) {
                $name = $this->queueNameFromKey(Cast::string($key));

                if ($name !== null) {
                    $found[$name] = true;
                }
            }
        } while (Cast::int($cursor) !== 0);

        $names = array_keys($found);
        sort($names);

        return $names;
    }

    protected function performDepth(string $queue): QueueDepth
    {
        return new QueueDepth(
            connection: $this->connection,
            queue: $queue,
            pending: (int) $this->redis()->llen($this->key($queue)),
            delayed: (int) $this->redis()->zcard($this->key($queue, ':delayed')),
            reserved: (int) $this->redis()->zcard($this->key($queue, ':reserved')),
            approximate: false,
            sampledAt: microtime(true),
        );
    }

    protected function performOldestPendingAge(string $queue): ?int
    {
        $oldest = $this->redis()->lindex($this->key($queue), 0);

        if (! is_string($oldest)) {
            return null;
        }

        $pushedAt = $this->pushedAt($this->decode($oldest));

        return $pushedAt === null ? null : max(0, (int) round(microtime(true) - $pushedAt));
    }

    protected function performPeek(string $queue, int $limit): array
    {
        $jobs = [];

        foreach ((array) $this->redis()->lrange($this->key($queue), 0, $limit - 1) as $member) {
            $payload = $this->decode((string) $member);

            $jobs[] = PendingJob::fromPayload(
                id: $this->identify($payload, (string) $member),
                queue: $queue,
                payload: $payload,
            );
        }

        // Top up from the delayed set so a queue whose work is all scheduled
        // does not look empty.
        $remaining = $limit - count($jobs);

        if ($remaining > 0) {
            $delayedKey = $this->key($queue, ':delayed');

            foreach ((array) $this->redis()->zrange($delayedKey, 0, $remaining - 1) as $member) {
                $payload = $this->decode((string) $member);
                $score = $this->redis()->zscore($delayedKey, $member);

                $jobs[] = PendingJob::fromPayload(
                    id: $this->identify($payload, (string) $member),
                    queue: $queue,
                    payload: $payload,
                    availableAt: is_numeric($score) ? (int) $score : null,
                    delayed: true,
                );
            }
        }

        return $jobs;
    }

    protected function performForget(string $queue, string $id): bool
    {
        if (($member = $this->findInList($this->key($queue), $id)) !== null) {
            return Cast::int($this->call('lrem', [$this->key($queue), 1, $member])) > 0;
        }

        $delayed = $this->key($queue, ':delayed');

        if (($member = $this->findInSortedSet($delayed, $id)) !== null) {
            return (int) $this->redis()->zrem($delayed, $member) > 0;
        }

        return false;
    }

    protected function performPromote(string $queue, string $id): bool
    {
        $delayed = $this->key($queue, ':delayed');

        $member = $this->findInSortedSet($delayed, $id);

        if ($member === null) {
            return false;
        }

        // Remove first, and only push if the removal actually won. Two
        // dashboards promoting the same job must not enqueue it twice.
        if ((int) $this->redis()->zrem($delayed, $member) === 0) {
            return false;
        }

        $this->redis()->rpush($this->key($queue), [$member]);
        $this->redis()->rpush($this->key($queue, ':notify'), [1]);

        return true;
    }

    protected function performPurge(string $queue): int
    {
        $depth = $this->performDepth($queue);

        $this->redis()->del(
            $this->key($queue),
            $this->key($queue, ':delayed'),
            $this->key($queue, ':reserved'),
            $this->key($queue, ':notify'),
        );

        return $depth->total() ?? 0;
    }

    /**
     * Walk a list in chunks looking for the member carrying this uuid.
     */
    protected function findInList(string $key, string $id): ?string
    {
        $length = min((int) $this->redis()->llen($key), $this->maxScan);

        for ($start = 0; $start < $length; $start += self::SCAN_CHUNK) {
            $end = min($start + self::SCAN_CHUNK - 1, $length - 1);

            foreach ((array) $this->redis()->lrange($key, $start, $end) as $member) {
                if ($this->identify($this->decode((string) $member), (string) $member) === $id) {
                    return (string) $member;
                }
            }
        }

        return null;
    }

    protected function findInSortedSet(string $key, string $id): ?string
    {
        $length = min((int) $this->redis()->zcard($key), $this->maxScan);

        for ($start = 0; $start < $length; $start += self::SCAN_CHUNK) {
            $end = min($start + self::SCAN_CHUNK - 1, $length - 1);

            foreach ((array) $this->redis()->zrange($key, $start, $end) as $member) {
                if ($this->identify($this->decode((string) $member), (string) $member) === $id) {
                    return (string) $member;
                }
            }
        }

        return null;
    }

    /**
     * A stable handle for a job. The payload uuid where there is one, and
     * otherwise a digest of the raw member, so raw pushes stay addressable.
     *
     * @param  array<array-key, mixed>  $payload
     */
    protected function identify(array $payload, string $raw): string
    {
        return is_string($payload['uuid'] ?? null) ? $payload['uuid'] : 'raw:'.md5($raw);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    protected function pushedAt(array $payload): ?float
    {
        $meta = Cast::array($payload['yardmaster'] ?? []);

        return Cast::nullableFloat($meta['pushed_at'] ?? $payload['createdAt'] ?? null);
    }

    protected function queueNameFromKey(string $key): ?string
    {
        if ($this->keyPrefix !== '' && str_starts_with($key, $this->keyPrefix)) {
            $key = substr($key, strlen($this->keyPrefix));
        }

        if (! str_starts_with($key, 'queues:')) {
            return null;
        }

        $name = substr($key, strlen('queues:'));

        // Strip the sibling structures back to the queue they belong to rather
        // than discarding them. A queue holding nothing but scheduled work
        // exists only as a ':delayed' sorted set, and dropping that key is how
        // a backed-up queue comes to look like no queue at all.
        foreach ([':delayed', ':reserved', ':notify'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $name = substr($name, 0, -strlen($suffix));

                break;
            }
        }

        return $name === '' ? null : $name;
    }

    protected function key(string $queue, string $suffix = ''): string
    {
        return 'queues:'.$queue.$suffix;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function decode(string $member): array
    {
        $decoded = json_decode($member, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function redis(): Connection
    {
        $name = $this->config['connection'] ?? 'default';

        /** @var Connection $connection */
        $connection = $this->redis->connection(is_string($name) ? $name : 'default');

        return $connection;
    }
}
