<?php

namespace Iocod\Yardmaster\Entries;

use Iocod\Yardmaster\Enums\RunStatus;

/**
 * One attempt at one job, on any driver.
 *
 * Attempts — not jobs — are the unit of storage. A job released three times and
 * then failed is four rows, which is the only shape that lets the dashboard
 * render an honest attempt timeline.
 */
final class RunEntry
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<array-key, mixed>|null  $payload
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $jobUuid,
        public readonly string $jobClass,
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $attempt,
        public readonly RunStatus $status,
        public readonly ?float $queuedAt,
        public readonly float $startedAt,
        public readonly ?float $finishedAt = null,
        public readonly ?float $waitMs = null,
        public readonly ?float $runtimeMs = null,
        public readonly ?int $peakMemoryKb = null,
        public readonly ?string $batchId = null,
        public readonly ?string $parentUuid = null,
        public readonly array $tags = [],
        public readonly ?array $payload = null,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $exceptionMessage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'uuid' => $this->uuid,
            'job_uuid' => $this->jobUuid,
            'job_class' => $this->jobClass,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'attempt' => $this->attempt,
            'status' => $this->status->value,
            'queued_at' => $this->queuedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'wait_ms' => $this->waitMs,
            'runtime_ms' => $this->runtimeMs,
            'peak_memory_kb' => $this->peakMemoryKb,
            'batch_id' => $this->batchId,
            'parent_uuid' => $this->parentUuid,
            'tags' => $this->tags === [] ? null : json_encode(array_values($this->tags)),
            'payload' => $this->payload === null ? null : json_encode($this->payload),
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage === null
                ? null
                : mb_substr($this->exceptionMessage, 0, 2000),
        ];
    }
}
