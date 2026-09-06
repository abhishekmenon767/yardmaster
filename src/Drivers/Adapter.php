<?php

namespace Iocod\Yardmaster\Drivers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Iocod\Yardmaster\Events\ActionPerformed;
use Iocod\Yardmaster\Exceptions\UnsupportedCapability;
use Iocod\Yardmaster\Support\Cast;
use Iocod\Yardmaster\Values\PendingJob;
use Iocod\Yardmaster\Values\QueueDepth;

/**
 * The capability gate.
 *
 * Every public operation is final and does the same three things: check the
 * capability, delegate to the driver-specific implementation, and — for
 * destructive work — announce what happened. Subclasses implement only the
 * perform* methods, so an adapter cannot bypass the gate even by accident.
 */
abstract class Adapter implements QueueDriverAdapter
{
    /**
     * @param  array<string, mixed>  $config  The queue connection's own config.
     */
    public function __construct(
        protected string $connection,
        protected array $config = [],
        protected ?Cache $cache = null,
        protected ?Dispatcher $events = null,
    ) {}

    /**
     * @return array<int, Capability>
     */
    abstract protected function supportedCapabilities(): array;

    public function connectionName(): string
    {
        return $this->connection;
    }

    final public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), strict: true);
    }

    final public function capabilities(): array
    {
        return $this->supportedCapabilities();
    }

    final public function queues(): array
    {
        $this->gate(Capability::ListQueues);

        return $this->performQueues();
    }

    /**
     * Depth is deliberately ungated: a driver that can answer two of the three
     * counts should still answer those two, with the third as null.
     */
    final public function depth(string $queue): QueueDepth
    {
        $seconds = $this->depthCacheSeconds();

        if ($seconds <= 0 || $this->cache === null) {
            return $this->performDepth($queue);
        }

        // One cached poll shared by every dashboard viewer. Without this, ten
        // people watching an SQS queue is ten times the API bill and a fast
        // route to being throttled.
        $key = "yardmaster:depth:{$this->connection}:{$queue}";

        $cached = $this->cache->get($key);

        if ($cached instanceof QueueDepth) {
            return $cached;
        }

        $depth = $this->performDepth($queue);

        $this->cache->put($key, $depth, $seconds);

        return $depth;
    }

    final public function oldestPendingAge(string $queue): ?int
    {
        $this->gate(Capability::OldestJobAge);

        return $this->performOldestPendingAge($queue);
    }

    final public function peek(string $queue, int $limit = 25): array
    {
        $this->gate(Capability::PeekPayloads);

        return $this->performPeek($queue, max(1, $limit));
    }

    final public function forget(string $queue, string $id): bool
    {
        $this->gate(Capability::DeleteById);

        $result = $this->performForget($queue, $id);

        $this->announce(Capability::DeleteById, $queue, $id, $result);

        return $result;
    }

    final public function promote(string $queue, string $id): bool
    {
        $this->gate(Capability::PromoteDelayed);

        $result = $this->performPromote($queue, $id);

        $this->announce(Capability::PromoteDelayed, $queue, $id, $result);

        return $result;
    }

    final public function purge(string $queue): int
    {
        $this->gate(Capability::PurgeQueue);

        $removed = $this->performPurge($queue);

        $this->markPurged($queue);

        $this->announce(Capability::PurgeQueue, $queue, null, $removed);

        return $removed;
    }

    public function purgeCooldownRemaining(string $queue): int
    {
        if ($this->purgeCooldownSeconds() <= 0 || $this->cache === null) {
            return 0;
        }

        $last = $this->cache->get($this->purgeCooldownKey($queue));

        if (! is_numeric($last)) {
            return 0;
        }

        return (int) max(0, ceil($this->purgeCooldownSeconds() - (microtime(true) - (float) $last)));
    }

    /**
     * How long a depth reading may be reused. Zero means always read live.
     */
    protected function depthCacheSeconds(): int
    {
        return Cast::int($this->config['yardmaster_depth_cache'] ?? 0);
    }

    protected function purgeCooldownSeconds(): int
    {
        return 0;
    }

    protected function markPurged(string $queue): void
    {
        if ($this->purgeCooldownSeconds() > 0 && $this->cache !== null) {
            $this->cache->put(
                $this->purgeCooldownKey($queue),
                microtime(true),
                $this->purgeCooldownSeconds(),
            );
        }
    }

    protected function purgeCooldownKey(string $queue): string
    {
        return "yardmaster:purged:{$this->connection}:{$queue}";
    }

    protected function gate(Capability $capability): void
    {
        if (! $this->supports($capability)) {
            throw new UnsupportedCapability($capability, $this->driver(), $this->connection);
        }
    }

    protected function announce(
        Capability $capability,
        string $queue,
        ?string $target,
        int|bool $result,
    ): void {
        $this->events?->dispatch(new ActionPerformed(
            action: $capability->value,
            connectionName: $this->connection,
            driver: $this->driver(),
            queue: $queue,
            target: $target,
            result: $result,
            capability: $capability,
            at: microtime(true),
        ));
    }

    /**
     * @return array<int, string>
     */
    protected function performQueues(): array
    {
        return [];
    }

    abstract protected function performDepth(string $queue): QueueDepth;

    protected function performOldestPendingAge(string $queue): ?int
    {
        return null;
    }

    /**
     * @return array<int, PendingJob>
     */
    protected function performPeek(string $queue, int $limit): array
    {
        return [];
    }

    protected function performForget(string $queue, string $id): bool
    {
        return false;
    }

    protected function performPromote(string $queue, string $id): bool
    {
        return false;
    }

    protected function performPurge(string $queue): int
    {
        return 0;
    }

    /**
     * An empty depth reading, for drivers that cannot answer any of it.
     */
    protected function unknownDepth(string $queue, bool $approximate = false): QueueDepth
    {
        return new QueueDepth(
            connection: $this->connection,
            queue: $queue,
            approximate: $approximate,
            sampledAt: microtime(true),
        );
    }
}
