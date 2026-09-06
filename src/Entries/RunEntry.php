<?php

namespace Abhishek\Yardmaster\Entries;

use Abhishek\Yardmaster\Enums\RunStatus;
use Abhishek\Yardmaster\Support\Cast;

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
        /** First application frame, as `file:line`. */
        public readonly ?string $exceptionFrame = null,
        /** Identity of the failure this attempt is an occurrence of. */
        public readonly ?string $fingerprint = null,
        /**
         * The fraction of attempts being recorded when this one was captured.
         * Carried through so aggregates can be scaled back up and marked
         * approximate rather than silently under-reporting.
         */
        public readonly float $sampleRate = 1.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
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
            'tags' => $this->tags,
            'payload' => $this->payload,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'exception_frame' => $this->exceptionFrame,
            'fingerprint' => $this->fingerprint,
            'sample_rate' => $this->sampleRate,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uuid: Cast::string($data['uuid'] ?? ''),
            jobUuid: isset($data['job_uuid']) ? Cast::string($data['job_uuid']) : null,
            jobClass: Cast::string($data['job_class'] ?? 'unknown'),
            connection: Cast::string($data['connection'] ?? ''),
            queue: Cast::string($data['queue'] ?? 'default'),
            attempt: Cast::int($data['attempt'] ?? 1),
            status: RunStatus::from(Cast::string($data['status'] ?? 'processed')),
            queuedAt: isset($data['queued_at']) ? Cast::float($data['queued_at']) : null,
            startedAt: Cast::float($data['started_at'] ?? 0),
            finishedAt: isset($data['finished_at']) ? Cast::float($data['finished_at']) : null,
            waitMs: isset($data['wait_ms']) ? Cast::float($data['wait_ms']) : null,
            runtimeMs: isset($data['runtime_ms']) ? Cast::float($data['runtime_ms']) : null,
            peakMemoryKb: isset($data['peak_memory_kb']) ? Cast::int($data['peak_memory_kb']) : null,
            batchId: isset($data['batch_id']) ? Cast::string($data['batch_id']) : null,
            parentUuid: isset($data['parent_uuid']) ? Cast::string($data['parent_uuid']) : null,
            tags: is_array($data['tags'] ?? null) ? array_values(array_filter($data['tags'], 'is_string')) : [],
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : null,
            exceptionClass: isset($data['exception_class']) ? Cast::string($data['exception_class']) : null,
            exceptionMessage: isset($data['exception_message']) ? Cast::string($data['exception_message']) : null,
            exceptionFrame: isset($data['exception_frame']) ? Cast::string($data['exception_frame']) : null,
            fingerprint: isset($data['fingerprint']) ? Cast::string($data['fingerprint']) : null,
            sampleRate: Cast::float($data['sample_rate'] ?? 1.0),
        );
    }

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
            'exception_frame' => $this->exceptionFrame,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
