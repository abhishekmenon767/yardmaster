<?php

namespace Abhishek\Yardmaster\Contracts;

use Abhishek\Yardmaster\Entries\RunEntry;

interface Ingest
{
    /**
     * Persist a flushed batch of entries.
     *
     * Implementations must never throw into the caller: a monitoring failure is
     * not permitted to fail the job being monitored.
     *
     * @param  array<int, RunEntry>  $entries
     */
    public function ingest(array $entries): void;
}
