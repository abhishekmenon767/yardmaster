<?php

namespace Iocod\Yardmaster\Http\Controllers;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Iocod\Yardmaster\Drivers\AdapterManager;
use Iocod\Yardmaster\Drivers\Capability;
use Iocod\Yardmaster\Events\ActionPerformed;
use Iocod\Yardmaster\Repositories\MetricsRepository;
use Iocod\Yardmaster\Support\Cast;

/**
 * Live queue state and the controls that act on it.
 *
 * Queue discovery combines two sources on purpose: what each driver can
 * enumerate right now, and what Plane A has ever seen run. The second catches
 * queues a driver cannot list, and queues that are idle this minute but were
 * backed up an hour ago.
 */
class QueueController extends Controller
{
    public function index(Request $request, AdapterManager $adapters, MetricsRepository $metrics): JsonResponse
    {
        $only = $request->query('connection');
        $known = $metrics->knownQueues(time() - 86400);
        $queues = [];

        foreach ($adapters->all() as $name => $adapter) {
            if (is_string($only) && $only !== '' && $only !== $name) {
                continue;
            }

            $names = [];

            foreach ($known as $seen) {
                if ($seen['connection'] === $name) {
                    $names[$seen['queue']] = true;
                }
            }

            if ($adapter->supports(Capability::ListQueues)) {
                try {
                    foreach ($adapter->queues() as $queue) {
                        $names[$queue] = true;
                    }
                } catch (\Throwable) {
                    // An unreachable driver must not blank the whole page; the
                    // queues telemetry knows about are still worth showing.
                }
            }

            foreach (array_keys($names) as $queue) {
                $depth = $adapter->depth((string) $queue);

                $queues[] = $depth->toArray() + [
                    'driver' => $adapter->driver(),
                    'purge_cooldown' => $adapter->purgeCooldownRemaining((string) $queue),
                    'paused' => $this->isPaused($name, (string) $queue),
                ];
            }
        }

        return $this->json(['data' => $queues]);
    }

    public function jobs(Request $request, AdapterManager $adapters): JsonResponse
    {
        [$connection, $queue] = $this->target($request);

        return $this->gated(fn () => [
            'data' => array_map(
                static fn ($job) => $job->toArray(),
                $adapters->for($connection)->peek($queue, (int) $request->query('limit', 25)),
            ),
        ]);
    }

    public function purge(Request $request, AdapterManager $adapters): JsonResponse
    {
        [$connection, $queue] = $this->target($request);

        return $this->gated(fn () => [
            'removed' => $adapters->for($connection)->purge($queue),
        ]);
    }

    public function forget(Request $request, AdapterManager $adapters): JsonResponse
    {
        [$connection, $queue] = $this->target($request);

        return $this->gated(fn () => [
            'deleted' => $adapters->for($connection)->forget($queue, Cast::string($request->input('id'))),
        ]);
    }

    public function promote(Request $request, AdapterManager $adapters): JsonResponse
    {
        [$connection, $queue] = $this->target($request);

        return $this->gated(fn () => [
            'promoted' => $adapters->for($connection)->promote($queue, Cast::string($request->input('id'))),
        ]);
    }

    /**
     * Pause and resume are the one pair of controls that work everywhere,
     * because the framework itself owns them: the worker checks a cache key
     * before reserving, whichever driver it is reserving from. Yardmaster
     * wraps that and audits it rather than reimplementing it.
     */
    public function pause(Request $request, QueueFactory $queue): JsonResponse
    {
        [$connection, $name] = $this->target($request);
        $ttl = Cast::int($request->input('ttl', 0));

        return $this->gated(function () use ($queue, $connection, $name, $ttl) {
            if (! $queue instanceof QueueManager) {
                return ['paused' => false];
            }

            $ttl > 0
                ? $queue->pauseFor($connection, $name, $ttl)
                : $queue->pause($connection, $name);

            event(new ActionPerformed(
                action: 'pause_queue',
                connectionName: $connection,
                driver: 'queue',
                queue: $name,
                context: $ttl > 0 ? ['ttl' => $ttl] : [],
                at: microtime(true),
            ));

            return ['paused' => true, 'ttl' => $ttl > 0 ? $ttl : null];
        });
    }

    public function resume(Request $request, QueueFactory $queue): JsonResponse
    {
        [$connection, $name] = $this->target($request);

        return $this->gated(function () use ($queue, $connection, $name) {
            if (! $queue instanceof QueueManager) {
                return ['paused' => false];
            }

            $queue->resume($connection, $name);

            event(new ActionPerformed(
                action: 'resume_queue',
                connectionName: $connection,
                driver: 'queue',
                queue: $name,
                at: microtime(true),
            ));

            return ['paused' => false];
        });
    }

    protected function isPaused(string $connection, string $queue): bool
    {
        $manager = app(QueueFactory::class);

        return $manager instanceof QueueManager && $manager->isPaused($connection, $queue);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function target(Request $request): array
    {
        // Connection and queue travel as parameters rather than path segments:
        // queue names are free-form strings on most drivers, and URL-encoding
        // them into a path is a reliable source of subtle routing bugs.
        return [
            Cast::string($request->input('connection') ?? $request->query('connection', '')),
            Cast::string($request->input('queue') ?? $request->query('queue', 'default')),
        ];
    }
}
