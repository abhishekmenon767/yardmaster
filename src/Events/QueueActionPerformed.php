<?php

namespace Iocod\Yardmaster\Events;

use Iocod\Yardmaster\Drivers\Capability;

/**
 * Raised after any destructive queue operation completes.
 *
 * Every such operation passes through one gate, so this is the single place an
 * audit trail can be hung without trusting each adapter to remember. Phase 3
 * persists these; until then the seam exists and applications can listen.
 */
class QueueActionPerformed
{
    public function __construct(
        public readonly Capability $capability,
        public readonly string $connectionName,
        public readonly string $driver,
        public readonly string $queue,
        public readonly ?string $target,
        public readonly int|bool $result,
        public readonly float $at,
    ) {}
}
