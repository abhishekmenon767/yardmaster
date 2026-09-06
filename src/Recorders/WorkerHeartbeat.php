<?php

namespace Iocod\Yardmaster\Recorders;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Iocod\Yardmaster\Support\Cast;
use Iocod\Yardmaster\Yardmaster;

/**
 * Keeps a row per running worker.
 *
 * Deliberately throttled: writing on every loop would put a query between every
 * job, and the roster does not need sub-second accuracy to answer the question
 * it exists for — "is anything consuming this queue, and has it wedged?".
 * Starting and stopping are written immediately regardless, because those are
 * the transitions an operator is watching for.
 */
class WorkerHeartbeat
{
    /**
     * @var array<int, class-string>
     */
    public array $listen = [
        WorkerStarting::class,
        Looping::class,
        JobProcessing::class,
        JobProcessed::class,
        JobFailed::class,
        WorkerStopping::class,
    ];

    protected ?float $lastBeat = null;

    protected float $startedAt;

    protected int $processed = 0;

    protected int $failed = 0;

    protected string $connection = '';

    protected string $queues = '';

    protected ?string $currentJob = null;

    protected ?float $currentJobStartedAt = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Yardmaster $yardmaster,
        protected DatabaseManager $db,
        protected Config $settings,
        protected array $config = [],
    ) {
        $this->startedAt = microtime(true);
    }

    public function record(object $event): void
    {
        $this->yardmaster->rescue(function () use ($event) {
            match (true) {
                $event instanceof WorkerStarting => $this->starting($event),
                $event instanceof Looping => $this->looping($event),
                $event instanceof JobProcessing => $this->working($event),
                $event instanceof JobProcessed => $this->finished(false),
                $event instanceof JobFailed => $this->finished(true),
                $event instanceof WorkerStopping => $this->stopping(),
                default => null,
            };
        });
    }

    protected function starting(WorkerStarting $event): void
    {
        $this->connection = (string) $event->connectionName;
        $this->queues = (string) $event->queue;
        $this->startedAt = microtime(true);

        $this->write('idle', force: true);
    }

    protected function looping(Looping $event): void
    {
        $this->connection = $this->connection ?: (string) $event->connectionName;
        $this->queues = $this->queues ?: (string) $event->queue;

        $this->write('idle');
    }

    protected function working(JobProcessing $event): void
    {
        $this->connection = $this->connection ?: (string) $event->connectionName;
        $this->currentJob = $event->job->resolveName();
        $this->currentJobStartedAt = microtime(true);

        $this->write('working');
    }

    protected function finished(bool $failed): void
    {
        $failed ? $this->failed++ : $this->processed++;

        $this->currentJob = null;
        $this->currentJobStartedAt = null;
    }

    protected function stopping(): void
    {
        // Remove rather than mark: a worker that has stopped is not a worker,
        // and leaving a tombstone makes the roster something to interpret
        // rather than something to read.
        $this->yardmaster->rescue(fn () => $this->table()->where('name', $this->name())->delete());
    }

    protected function write(string $status, bool $force = false): void
    {
        $now = microtime(true);

        if (! $force && $this->lastBeat !== null && ($now - $this->lastBeat) < $this->interval()) {
            return;
        }

        $this->lastBeat = $now;

        $row = [
            'host' => gethostname() ?: 'unknown',
            'pid' => getmypid() ?: 0,
            'connection' => $this->connection ?: 'unknown',
            'queues' => $this->queues ?: 'default',
            'status' => $status,
            'current_job' => $this->currentJob,
            'current_job_started_at' => $this->currentJobStartedAt,
            'memory_kb' => (int) round(memory_get_usage(true) / 1024),
            'processed' => $this->processed,
            'failed' => $this->failed,
            'started_at' => $this->startedAt,
            'last_seen' => $now,
        ];

        $this->table()->updateOrInsert(['name' => $this->name()], $row);
    }

    protected function interval(): float
    {
        return max(1.0, Cast::float($this->config['interval'] ?? 5));
    }

    protected function name(): string
    {
        return (gethostname() ?: 'unknown').':'.(getmypid() ?: 0);
    }

    protected function table(): Builder
    {
        $connection = $this->settings->get('yardmaster.storage.connection');
        $table = $this->settings->get('yardmaster.storage.workers_table', 'yard_workers');

        return $this->db->connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'yard_workers');
    }
}
