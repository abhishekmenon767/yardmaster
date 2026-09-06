<?php

namespace Abhishek\Yardmaster;

use Abhishek\Yardmaster\Contracts\Ingest;
use Abhishek\Yardmaster\Entries\RunEntry;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * The package's entry point and shared state.
 *
 * Two responsibilities beyond buffering: it knows which job the current worker
 * is running (so jobs dispatched from inside a job can be linked to their
 * parent), and it owns the rescue boundary that guarantees a Yardmaster failure
 * never becomes an application failure.
 */
class Yardmaster
{
    /** @var array<int, Closure> */
    protected array $filters = [];

    /** @var array<int, Closure> */
    protected array $tagResolvers = [];

    protected ?Closure $exceptionHandler = null;

    /**
     * The uuid of the job this process is currently running, if any. Set on
     * JobProcessing and cleared when the attempt ends.
     */
    protected ?string $currentJobUuid = null;

    protected bool $recording = true;

    public function __construct(
        protected Application $app,
        protected Buffer $buffer,
        /**
         * How many entries may accumulate before the buffer is written.
         *
         * One means every attempt is durable the instant it ends, at the cost
         * of paying the aggregate write once per job. Raising it amortises that
         * cost across a batch and risks losing at most this many attempts if a
         * worker is killed outright — telemetry, never work.
         */
        protected int $flushThreshold = 1,
    ) {}

    /**
     * Buffer an entry, unless a filter rejects it.
     */
    public function record(RunEntry $entry): void
    {
        if (! $this->recording) {
            return;
        }

        foreach ($this->filters as $filter) {
            if ($filter($entry) === false) {
                return;
            }
        }

        $this->buffer->push($entry);

        if ($this->buffer->count() >= $this->flushThreshold) {
            $this->flush();
        }
    }

    /**
     * Hand everything buffered to the configured ingest driver.
     */
    public function flush(): void
    {
        if ($this->buffer->isEmpty()) {
            return;
        }

        $entries = $this->buffer->drain();

        $this->rescue(fn () => $this->app->make(Ingest::class)->ingest($entries));
    }

    /**
     * Run a callback, swallowing anything it throws.
     *
     * Every listener body and every write goes through here. Silence is the
     * correct default for a monitoring package; the handler exists so an
     * operator can opt into seeing the noise.
     */
    public function rescue(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            try {
                ($this->exceptionHandler ?? fn () => null)($e);
            } catch (Throwable) {
                // Nothing sensible left to do.
            }
        }

        return null;
    }

    public function handleExceptionsUsing(Closure $handler): static
    {
        $this->exceptionHandler = $handler;

        return $this;
    }

    /**
     * Reject entries before they are buffered.
     */
    public function filter(Closure $filter): static
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Contribute extra tags to jobs at dispatch time. The callback receives the
     * job instance and should return an array of strings.
     */
    public function tagsUsing(Closure $resolver): static
    {
        $this->tagResolvers[] = $resolver;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function resolveTags(mixed $job): array
    {
        $tags = [];

        foreach ($this->tagResolvers as $resolver) {
            $resolved = $this->rescue(fn () => $resolver($job));

            if (is_array($resolved)) {
                $tags = array_merge($tags, $resolved);
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($tag) => is_string($tag) ? $tag : null,
            $tags,
        ))));
    }

    public function currentJobUuid(): ?string
    {
        return $this->currentJobUuid;
    }

    public function enteringJob(?string $uuid): void
    {
        $this->currentJobUuid = $uuid;
    }

    public function leavingJob(): void
    {
        $this->currentJobUuid = null;
    }

    public function pauseRecording(): void
    {
        $this->recording = false;
    }

    public function resumeRecording(): void
    {
        $this->recording = true;
    }

    public function isRecording(): bool
    {
        return $this->recording;
    }

    public function buffer(): Buffer
    {
        return $this->buffer;
    }

    /**
     * Reset all per-request state. Called on Octane's RequestTerminated so that
     * a worker serving a second request never inherits the first one's state.
     */
    public function reset(): void
    {
        $this->buffer->flushState();
        $this->currentJobUuid = null;
    }
}
