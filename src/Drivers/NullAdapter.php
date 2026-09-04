<?php

namespace Iocod\Yardmaster\Drivers;

use Iocod\Yardmaster\Values\QueueDepth;

/**
 * The adapter for drivers with nothing to introspect — sync, null, and any
 * driver Yardmaster has not learned yet.
 *
 * It supports nothing and says so. That is the correct behaviour for an unknown
 * driver: the dashboard shows recorded history (Plane A works regardless) with
 * every control disabled, rather than guessing.
 */
class NullAdapter extends Adapter
{
    public function __construct(
        protected string $connection,
        protected string $driverName = 'null',
        array $config = [],
    ) {
        parent::__construct($connection, $config);
    }

    public function driver(): string
    {
        return $this->driverName;
    }

    protected function supportedCapabilities(): array
    {
        return [];
    }

    protected function performDepth(string $queue): QueueDepth
    {
        return $this->unknownDepth($queue);
    }
}
