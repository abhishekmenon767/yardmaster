<?php

namespace Iocod\Yardmaster\Exceptions;

use Iocod\Yardmaster\Drivers\Capability;
use RuntimeException;

/**
 * Thrown when a capability-gated operation is attempted on a driver that cannot
 * perform it.
 *
 * Reaching this exception means a caller skipped the gate: the dashboard is
 * expected to consult supports() and disable the control, naming the reason,
 * long before anyone clicks. It exists so that a missed check fails loudly in
 * development rather than silently in production.
 */
class UnsupportedCapability extends RuntimeException
{
    public function __construct(
        public readonly Capability $capability,
        public readonly string $driver,
        public readonly string $connectionName,
    ) {
        parent::__construct(sprintf(
            'The [%s] driver on connection [%s] cannot %s.',
            $driver,
            $connectionName,
            lcfirst($capability->label()),
        ));
    }
}
