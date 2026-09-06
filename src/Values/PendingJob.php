<?php

namespace Iocod\Yardmaster\Values;

use Iocod\Yardmaster\Support\Cast;

/**
 * A job sitting on a queue, seen without consuming it.
 *
 * The id is whatever the driver uses to address a single job — a table primary
 * key, a Beanstalkd job id, or (on Redis, which has no such handle) the job's
 * own uuid, which the adapter resolves back to a list member when asked to act.
 */
final class PendingJob
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $uuid,
        public readonly string $jobClass,
        public readonly string $queue,
        public readonly int $attempts = 0,
        public readonly ?int $availableAt = null,
        public readonly ?int $createdAt = null,
        public readonly bool $delayed = false,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromPayload(
        string $id,
        string $queue,
        array $payload,
        ?int $availableAt = null,
        ?int $createdAt = null,
        bool $delayed = false,
    ): self {
        return new self(
            id: $id,
            uuid: isset($payload['uuid']) && is_string($payload['uuid']) ? $payload['uuid'] : null,
            jobClass: Cast::string(
                Cast::array($payload['data'] ?? [])['commandName'] ?? $payload['displayName'] ?? null,
                'unknown',
            ),
            queue: $queue,
            attempts: Cast::int($payload['attempts'] ?? 0),
            availableAt: $availableAt,
            createdAt: $createdAt ?? (isset($payload['createdAt']) ? Cast::int($payload['createdAt']) : null),
            delayed: $delayed,
            payload: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'job_class' => $this->jobClass,
            'queue' => $this->queue,
            'attempts' => $this->attempts,
            'available_at' => $this->availableAt,
            'created_at' => $this->createdAt,
            'delayed' => $this->delayed,
        ];
    }
}
