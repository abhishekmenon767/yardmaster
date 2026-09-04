<?php

namespace Iocod\Yardmaster\Drivers;

use Iocod\Yardmaster\Exceptions\UnsupportedCapability;
use Iocod\Yardmaster\Values\PendingJob;
use Iocod\Yardmaster\Values\QueueDepth;

/**
 * Live introspection and control for one queue connection.
 *
 * Adapters are the only part of Yardmaster that knows a driver exists. Every
 * gated method throws UnsupportedCapability rather than degrading silently,
 * because a control that quietly does nothing is worse than one that is visibly
 * unavailable.
 */
interface QueueDriverAdapter
{
    /** The queue driver this adapter speaks, e.g. 'sqs'. */
    public function driver(): string;

    /** The configured connection name this adapter is bound to. */
    public function connectionName(): string;

    public function supports(Capability $capability): bool;

    /** @return array<int, Capability> */
    public function capabilities(): array;

    /**
     * Queue names known to this connection.
     *
     * @return array<int, string>
     *
     * @throws UnsupportedCapability
     */
    public function queues(): array;

    /**
     * Current depth. Never throws: unanswerable components come back as null,
     * which the dashboard renders as "unknown" rather than as zero.
     */
    public function depth(string $queue): QueueDepth;

    /**
     * Seconds since the oldest pending job was made available, or null.
     *
     * @throws UnsupportedCapability
     */
    public function oldestPendingAge(string $queue): ?int;

    /**
     * Inspect queued jobs without consuming them.
     *
     * @return array<int, PendingJob>
     *
     * @throws UnsupportedCapability
     */
    public function peek(string $queue, int $limit = 25): array;

    /**
     * Remove one job. Returns false when the job was already gone.
     *
     * @throws UnsupportedCapability
     */
    public function forget(string $queue, string $id): bool;

    /**
     * Make a delayed job available immediately.
     *
     * @throws UnsupportedCapability
     */
    public function promote(string $queue, string $id): bool;

    /**
     * Remove everything on the queue, returning how many jobs were removed
     * where the driver can say, or -1 where it cannot.
     *
     * @throws UnsupportedCapability
     */
    public function purge(string $queue): int;

    /**
     * Seconds until purge may be called again. Zero when it is available now.
     *
     * SQS rate-limits PurgeQueue to once per minute, so the dashboard needs to
     * know before the click rather than after.
     */
    public function purgeCooldownRemaining(string $queue): int;
}
