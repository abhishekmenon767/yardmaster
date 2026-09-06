<?php

namespace Abhishek\Yardmaster\Ingest;

use Abhishek\Yardmaster\Concerns\CallsRedis;
use Abhishek\Yardmaster\Contracts\Drainable;
use Abhishek\Yardmaster\Contracts\Ingest;
use Abhishek\Yardmaster\Entries\RunEntry;
use Abhishek\Yardmaster\Support\Cast;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Buffers telemetry into a Redis stream for `yard:work` to drain.
 *
 * The point is to get aggregation off the request and off the worker: a flush
 * becomes one XADD instead of an insert plus a read-merge-write per bucket. The
 * stream is capped, so a drainer that falls behind or dies costs bounded memory
 * and loses the oldest telemetry rather than filling the instance.
 *
 * This should not share a Redis connection with a Redis-backed queue. Telemetry
 * competing with the queue it is measuring is a poor trade.
 */
class RedisIngest implements Drainable, Ingest
{
    use CallsRedis;

    /**
     * XADD's argument order differs between phpredis and predis, and predis has
     * no consumer-group commands at all. A Lua call sidesteps both: it is
     * identical on either client, and it trims in the same round trip.
     */
    protected const APPEND = <<<'LUA'
    return redis.call('XADD', KEYS[1], 'MAXLEN', '~', ARGV[1], '*', 'entries', ARGV[2])
    LUA;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        protected RedisFactory $factory,
        protected array $settings = [],
    ) {}

    public function ingest(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        foreach (array_chunk($entries, $this->chunk()) as $chunk) {
            $payload = json_encode(array_map(
                static fn (RunEntry $entry) => $entry->toArray(),
                $chunk,
            ));

            if ($payload === false) {
                continue;
            }

            $this->call('eval', [self::APPEND, 1, $this->stream(), $this->trim(), $payload]);
        }
    }

    public function pending(): int
    {
        return Cast::int($this->call('xlen', [$this->stream()]));
    }

    public function read(int $limit): array
    {
        $rows = $this->call('xrange', [$this->stream(), '-', '+', max(1, $limit)]);

        if (! is_array($rows)) {
            return [];
        }

        $batches = [];

        foreach ($rows as $id => $fields) {
            $entries = $this->decode(is_array($fields) ? ($fields['entries'] ?? null) : null);

            // An unreadable batch is still acknowledged by the caller: leaving
            // it in the stream would block every later batch behind it forever.
            $batches[(string) $id] = $entries;
        }

        return $batches;
    }

    public function forget(array $ids): void
    {
        if ($ids !== []) {
            $this->call('xdel', [$this->stream(), array_values($ids)]);
        }
    }

    /**
     * @return array<int, RunEntry>
     */
    protected function decode(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $entries = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            try {
                $entries[] = RunEntry::fromArray(Cast::array($row));
            } catch (Throwable) {
                // A batch written by an older version of the package may not
                // decode. Skip the row rather than stalling the drain.
            }
        }

        return $entries;
    }

    public function stream(): string
    {
        $stream = $this->settings['stream'] ?? 'yardmaster:ingest';

        return is_string($stream) ? $stream : 'yardmaster:ingest';
    }

    protected function trim(): int
    {
        return max(1000, Cast::int($this->settings['trim'] ?? 10000));
    }

    /**
     * @return int<1, max>
     */
    protected function chunk(): int
    {
        return max(1, Cast::int($this->settings['chunk'] ?? 250));
    }

    protected function redis(): Connection
    {
        $name = $this->settings['connection'] ?? 'default';

        /** @var Connection $connection */
        $connection = $this->factory->connection(is_string($name) ? $name : 'default');

        return $connection;
    }
}
