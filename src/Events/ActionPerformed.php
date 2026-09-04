<?php

namespace Iocod\Yardmaster\Events;

use Iocod\Yardmaster\Drivers\Capability;

/**
 * Raised after any operation that changes queue state.
 *
 * One event for every such operation, whether it came from a driver adapter or
 * from the failed-job provider, so the audit trail has a single seam rather
 * than one per subsystem. Anyone in a regulated environment needs to be able to
 * answer "who purged that queue"; this is how.
 */
class ActionPerformed
{
    /**
     * @param  string  $action  Machine name, e.g. 'purge_queue' or 'retry_failed'.
     * @param  Capability|null  $capability  Set when a driver adapter performed it.
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $action,
        public readonly string $connectionName,
        public readonly string $driver,
        public readonly ?string $queue = null,
        public readonly ?string $target = null,
        public readonly int|bool $result = true,
        public readonly ?Capability $capability = null,
        public readonly array $context = [],
        public readonly ?float $at = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->result !== false;
    }
}
