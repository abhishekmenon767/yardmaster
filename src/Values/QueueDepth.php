<?php

namespace Abhishek\Yardmaster\Values;

/**
 * How much work is sitting on one queue, right now.
 *
 * Every count is nullable, and null means "this driver cannot answer" rather
 * than zero. Conflating the two is how a dashboard ends up reporting an empty
 * queue that is in fact backed up.
 */
final class QueueDepth
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly ?int $pending = null,
        public readonly ?int $delayed = null,
        public readonly ?int $reserved = null,
        /**
         * True when the driver reports estimates rather than exact counts, as
         * SQS does. The dashboard renders these with a leading '~'.
         */
        public readonly bool $approximate = false,
        public readonly ?float $sampledAt = null,
    ) {}

    public function total(): ?int
    {
        $known = array_filter(
            [$this->pending, $this->delayed, $this->reserved],
            static fn (?int $count) => $count !== null,
        );

        return $known === [] ? null : array_sum($known);
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /**
     * How stale this reading is, in seconds. Zero for a live read.
     */
    public function ageInSeconds(): float
    {
        return $this->sampledAt === null ? 0.0 : max(0.0, microtime(true) - $this->sampledAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'pending' => $this->pending,
            'delayed' => $this->delayed,
            'reserved' => $this->reserved,
            'total' => $this->total(),
            'approximate' => $this->approximate,
            'sampled_at' => $this->sampledAt,
        ];
    }
}
