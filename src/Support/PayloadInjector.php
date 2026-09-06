<?php

namespace Iocod\Yardmaster\Support;

use Illuminate\Database\Eloquent\Model;
use Iocod\Yardmaster\Yardmaster;
use ReflectionObject;

/**
 * Attaches Yardmaster metadata to every job payload at dispatch.
 *
 * Registered through Queue::createPayloadUsing, which the framework calls while
 * the payload's command is still the live job object — so tags and parentage
 * are read directly off the instance, with no deserialization cost. The
 * metadata then travels with the job through whichever driver carries it, which
 * is how a wait time survives the hop between the dispatching process and the
 * worker that eventually runs the job.
 */
class PayloadInjector
{
    /**
     * @param  array<array-key, mixed>  $config
     */
    public function __construct(
        protected Yardmaster $yardmaster,
        protected array $config = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    public function __invoke(string $connection, ?string $queue, array $payload): array
    {
        $injected = $this->yardmaster->rescue(function () use ($payload) {
            $job = Cast::array($payload['data'] ?? [])['command'] ?? null;

            return [
                'yardmaster' => array_filter([
                    // Millisecond resolution; the framework's own createdAt is
                    // whole seconds, which is useless for measuring queue wait.
                    'pushed_at' => microtime(true),

                    // Set when this job was dispatched from inside another job,
                    // which turns a fan-out into a tree instead of a flat list.
                    'parent' => $this->yardmaster->currentJobUuid(),

                    'tags' => is_object($job) ? $this->tags($job) : [],
                ], static fn ($value) => $value !== null && $value !== []),
            ];
        });

        return Cast::array($injected);
    }

    /**
     * @return array<int, string>
     */
    protected function tags(object $job): array
    {
        $tags = $this->yardmaster->resolveTags($job);

        if ($this->config['auto_model_tags'] ?? true) {
            $tags = array_merge($tags, $this->modelTags($job));
        }

        return array_values(array_unique($tags));
    }

    /**
     * Tag a job with the Eloquent models it carries, so the dashboard can
     * answer "show me everything that touched order 4471".
     *
     * @return array<int, string>
     */
    protected function modelTags(object $job): array
    {
        $tags = [];

        foreach ((new ReflectionObject($job))->getProperties() as $property) {
            $property->setAccessible(true);

            if (! $property->isInitialized($job)) {
                continue;
            }

            $value = $property->getValue($job);

            foreach (is_iterable($value) ? $value : [$value] as $item) {
                if ($item instanceof Model && $item->getKey() !== null) {
                    $tags[] = $item::class.':'.Cast::string($item->getKey());
                }
            }
        }

        return $tags;
    }
}
