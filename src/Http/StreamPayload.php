<?php

namespace Iocod\Yardmaster\Http;

use Iocod\Yardmaster\Drivers\AdapterManager;
use Iocod\Yardmaster\Drivers\Capability;
use Iocod\Yardmaster\Repositories\MetricsRepository;
use Iocod\Yardmaster\Support\Cast;
use Throwable;

/**
 * One tick of the live stream.
 *
 * Kept separate from the streaming response so the thing that is hard to test
 * (an open connection that never ends) and the thing worth testing (the shape
 * and honesty of the data) are not the same object.
 */
class StreamPayload
{
    public function __construct(
        protected AdapterManager $adapters,
        protected MetricsRepository $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $now = time();
        $queues = [];

        foreach ($this->adapters->all() as $name => $adapter) {
            foreach ($this->queueNames($name, $adapter->supports(Capability::ListQueues) ? $adapter : null) as $queue) {
                try {
                    $queues[] = $adapter->depth($queue)->toArray() + ['driver' => $adapter->driver()];
                } catch (Throwable) {
                    // One unreachable connection must not stop the stream for
                    // the others.
                }
            }
        }

        $summary = $this->metrics->summary($now - 300, $now);

        return [
            'at' => $now,
            'queues' => $queues,
            'recent' => [
                'total' => $summary['total'],
                'failed' => $summary['failed'],
                'failure_rate' => $summary['failure_rate'],
                'throughput_per_minute' => $summary['throughput_per_minute'],
                'p95_ms' => Cast::array($summary['runtime_ms'] ?? [])['p95'] ?? null,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function queueNames(string $connection, ?object $adapter): array
    {
        $names = [];

        foreach ($this->metrics->knownQueues(time() - 3600) as $seen) {
            if ($seen['connection'] === $connection) {
                $names[$seen['queue']] = true;
            }
        }

        if ($adapter !== null && method_exists($adapter, 'queues')) {
            try {
                foreach ($adapter->queues() as $queue) {
                    $names[(string) $queue] = true;
                }
            } catch (Throwable) {
                // Fall back to what telemetry knows.
            }
        }

        return array_map('strval', array_keys($names));
    }
}
