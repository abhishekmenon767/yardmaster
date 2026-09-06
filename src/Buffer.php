<?php

namespace Abhishek\Yardmaster;

use Abhishek\Yardmaster\Entries\RunEntry;

/**
 * A per-process holding area for entries awaiting ingest.
 *
 * Nothing here touches the network or the database. Keeping the write off the
 * hot path is what makes recording affordable; the buffer is drained once a job
 * finishes, or once the request terminates, or early if it hits its cap.
 */
class Buffer
{
    /** @var array<int, RunEntry> */
    protected array $entries = [];

    public function __construct(
        protected int $limit = 1000,
    ) {}

    public function push(RunEntry $entry): void
    {
        // A full buffer means the drain is not keeping up. Dropping the oldest
        // entry is the right trade: unbounded memory growth in a long-lived
        // worker is a far worse outcome than a gap in telemetry.
        if (count($this->entries) >= $this->limit) {
            array_shift($this->entries);
        }

        $this->entries[] = $entry;
    }

    /**
     * Take everything currently buffered, leaving the buffer empty.
     *
     * @return array<int, RunEntry>
     */
    public function drain(): array
    {
        $entries = $this->entries;

        $this->entries = [];

        return $entries;
    }

    /**
     * @return array<int, RunEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function flushState(): void
    {
        $this->entries = [];
    }
}
