<?php

namespace Abhishek\Yardmaster\Recorders;

use Abhishek\Yardmaster\Entries\RunEntry;
use Abhishek\Yardmaster\Enums\RunStatus;
use Abhishek\Yardmaster\Support\Cast;
use Abhishek\Yardmaster\Support\Fingerprint;
use Abhishek\Yardmaster\Support\Redactor;
use Abhishek\Yardmaster\Yardmaster;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records one entry per job attempt, on every driver.
 *
 * This recorder deliberately knows nothing about queue drivers. It listens only
 * to framework events, which every driver raises identically, and reads only
 * the Job contract. That is what makes Plane A's guarantee — works everywhere,
 * always — hold without a single driver conditional.
 */
class JobRuns
{
    /**
     * @var array<int, class-string>
     */
    public array $listen = [
        // Workers are long-lived, so the framework's terminating callbacks
        // never arrive. Anything still buffered is written as the worker winds
        // down instead.
        WorkerStopping::class,
        JobProcessing::class,
        JobProcessed::class,
        JobExceptionOccurred::class,
        JobReleasedAfterException::class,
        JobFailed::class,
        JobTimedOut::class,
    ];

    /**
     * Attempts opened by JobProcessing and not yet closed.
     *
     * @var array<string, array{started_at: float, exception: ?Throwable}>
     */
    protected array $pending = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Yardmaster $yardmaster,
        protected Redactor $redactor,
        protected array $config = [],
        protected string $basePath = '',
    ) {}

    public function record(object $event): void
    {
        $this->yardmaster->rescue(fn () => match (true) {
            $event instanceof JobProcessing => $this->open($event),
            $event instanceof JobExceptionOccurred => $this->noteException($event),
            $event instanceof JobProcessed => $this->close($event->connectionName, $event->job, RunStatus::Processed),
            $event instanceof JobReleasedAfterException => $this->close($event->connectionName, $event->job, RunStatus::Released),
            $event instanceof JobFailed => $this->close($event->connectionName, $event->job, RunStatus::Failed, $event->exception),
            $event instanceof JobTimedOut => $this->close($event->connectionName, $event->job, RunStatus::TimedOut),
            $event instanceof WorkerStopping => $this->yardmaster->flush(),
            default => null,
        });
    }

    protected function open(JobProcessing $event): void
    {
        if ($this->shouldIgnore($event->job)) {
            return;
        }

        $this->pending[$this->key($event->job)] = [
            'started_at' => microtime(true),
            'exception' => null,
        ];

        // A true per-attempt peak, rather than the peak of every job this
        // long-lived worker has ever run. Requires PHP 8.2+, which our floor
        // of 8.3 guarantees.
        if ($this->tracksMemory()) {
            memory_reset_peak_usage();
        }

        $this->yardmaster->enteringJob($event->job->uuid());
    }

    protected function noteException(JobExceptionOccurred $event): void
    {
        $key = $this->key($event->job);

        if (isset($this->pending[$key])) {
            $this->pending[$key]['exception'] = $event->exception;
        }
    }

    protected function close(string $connectionName, Job $job, RunStatus $status, ?Throwable $exception = null): void
    {
        $this->yardmaster->leavingJob();

        $key = $this->key($job);
        $pending = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        // No opened attempt means we ignored this job at JobProcessing, or the
        // worker started before Yardmaster booted. Either way there is nothing
        // honest to record.
        if ($pending === null) {
            return;
        }

        $rate = $this->sampleRate();

        if (! $this->sampled($rate)) {
            return;
        }

        $frame = $exception === null ? null : Fingerprint::frame($exception, $this->basePath);
        $finishedAt = microtime(true);
        $payload = $job->payload();
        $meta = $payload['yardmaster'] ?? [];
        $exception ??= $pending['exception'];

        $queuedAt = isset($meta['pushed_at'])
            ? (float) $meta['pushed_at']
            : (isset($payload['createdAt']) ? (float) $payload['createdAt'] : null);

        $this->yardmaster->record(new RunEntry(
            uuid: (string) Str::uuid(),
            jobUuid: $job->uuid(),
            jobClass: $this->jobClass($job, $payload),
            connection: $connectionName,
            queue: $this->queueName($job),
            attempt: max(1, (int) $job->attempts()),
            status: $status,
            queuedAt: $queuedAt,
            startedAt: $pending['started_at'],
            finishedAt: $finishedAt,
            waitMs: $queuedAt === null
                ? null
                : max(0.0, ($pending['started_at'] - $queuedAt) * 1000),
            runtimeMs: ($finishedAt - $pending['started_at']) * 1000,
            peakMemoryKb: $this->tracksMemory()
                ? (int) round(memory_get_peak_usage(true) / 1024)
                : null,
            batchId: $payload['data']['batchId'] ?? null,
            parentUuid: $meta['parent'] ?? null,
            tags: array_values(array_filter((array) ($meta['tags'] ?? []), 'is_string')),
            payload: $this->capturePayload($payload),
            exceptionClass: $exception === null ? null : $exception::class,
            exceptionMessage: $exception?->getMessage(),
            exceptionFrame: $frame,
            fingerprint: $exception === null
                ? null
                : Fingerprint::for($exception::class, $exception->getMessage(), $frame),
            sampleRate: $rate,
        ));

    }

    /**
     * The stored payload, minus the parts that are noise or hazard.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>|null
     */
    protected function capturePayload(array $payload): ?array
    {
        if (! ($this->config['capture_payloads'] ?? true)) {
            return null;
        }

        // The serialized command is opaque, frequently enormous, and is the
        // single most likely place for credentials to be sitting.
        $data = Cast::array($payload['data'] ?? []);
        unset($data['command'], $payload['yardmaster']);
        $payload['data'] = $data;

        return $this->redactor->scrub($payload);
    }

    /**
     * SQS reports its queue as a full URL; every other driver reports a name.
     * Normalising here keeps one queue from appearing twice in the dashboard.
     */
    protected function queueName(Job $job): string
    {
        $queue = (string) $job->getQueue();

        if (str_contains($queue, '/')) {
            $queue = (string) Str::afterLast($queue, '/');
        }

        // Redis queues carry Laravel's braces in some configurations.
        return trim($queue, '{}') ?: 'default';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    protected function jobClass(Job $job, array $payload): string
    {
        $data = Cast::array($payload['data'] ?? []);

        return Cast::string(
            $data['commandName'] ?? $payload['displayName'] ?? null,
            $job->resolveName(),
        );
    }

    protected function shouldIgnore(Job $job): bool
    {
        $name = $job->resolveName();

        foreach (Cast::strings($this->config['ignore'] ?? []) as $pattern) {
            if (@preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function sampleRate(): float
    {
        $rate = Cast::float($this->config['sample'] ?? 1.0);

        return max(0.0, min(1.0, $rate));
    }

    protected function sampled(float $rate): bool
    {
        return $rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate;
    }

    protected function tracksMemory(): bool
    {
        return (bool) ($this->config['track_memory'] ?? true)
            && function_exists('memory_reset_peak_usage');
    }

    /**
     * Attempts of the same job must not collide, so the attempt number is part
     * of the key.
     */
    protected function key(Job $job): string
    {
        return ($job->uuid() ?: (string) $job->getJobId()).':'.$job->attempts();
    }
}
