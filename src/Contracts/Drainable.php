<?php

namespace Iocod\Yardmaster\Contracts;

use Iocod\Yardmaster\Entries\RunEntry;

/**
 * An ingest driver that buffers entries somewhere a separate process drains.
 *
 * Implementing this is what makes an ingest driver eligible for `yard:work`.
 * The database driver deliberately does not: it has already written by the time
 * it returns, so there is nothing to drain.
 */
interface Drainable
{
    /** How many undrained batches are waiting. */
    public function pending(): int;

    /**
     * Read up to $limit batches without removing them.
     *
     * @return array<string, array<int, RunEntry>> Keyed by an opaque batch id.
     */
    public function read(int $limit): array;

    /**
     * Remove batches that have been written through.
     *
     * @param  array<int, string>  $ids
     */
    public function forget(array $ids): void;
}
