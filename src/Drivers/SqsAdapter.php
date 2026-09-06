<?php

namespace Iocod\Yardmaster\Drivers;

use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\SqsQueue;
use Iocod\Yardmaster\Support\Cast;
use Iocod\Yardmaster\Values\QueueDepth;
use Throwable;

/**
 * The SQS driver, and the reason the capability gate exists.
 *
 * SQS is a managed queue with a deliberately narrow surface: it will tell you
 * roughly how many messages are in each state and let you empty a queue, and
 * that is all. There is no way to list messages without receiving them (which
 * hides them from workers), and no way to address one message without first
 * holding its receipt handle. Those controls are therefore not degraded here —
 * they are absent, and the dashboard says so.
 *
 * Counts are estimates that lag by seconds, so every reading is marked
 * approximate and shared from one cached poll rather than re-fetched per
 * viewer.
 */
class SqsAdapter extends Adapter
{
    /** SQS permits PurgeQueue once per 60 seconds per queue. */
    protected const PURGE_COOLDOWN = 60;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected string $connection,
        protected array $config,
        protected SqsQueue $queue,
        ?Cache $cache = null,
        ?Dispatcher $events = null,
    ) {
        parent::__construct($connection, $config, $cache, $events);
    }

    public function driver(): string
    {
        return 'sqs';
    }

    protected function supportedCapabilities(): array
    {
        return [
            Capability::CountPending,
            Capability::CountDelayed,
            Capability::CountReserved,
            Capability::ListQueues,
            Capability::PurgeQueue,
        ];
    }

    protected function performQueues(): array
    {
        $result = $this->client()->listQueues([]);

        $urls = $result['QueueUrls'] ?? [];

        $names = array_map(
            static fn ($url) => Cast::string(substr(strrchr(Cast::string($url), '/') ?: '/', 1)),
            is_array($urls) ? $urls : [],
        );

        $names = array_values(array_filter($names));
        sort($names);

        return $names;
    }

    protected function performDepth(string $queue): QueueDepth
    {
        $attributes = $this->attributes($queue);

        return new QueueDepth(
            connection: $this->connection,
            queue: $queue,
            pending: $this->intOrNull($attributes['ApproximateNumberOfMessages'] ?? null),
            delayed: $this->intOrNull($attributes['ApproximateNumberOfMessagesDelayed'] ?? null),
            reserved: $this->intOrNull($attributes['ApproximateNumberOfMessagesNotVisible'] ?? null),
            // Never presented as exact: these lag, and an operator making a
            // scaling decision needs to know the number is a estimate.
            approximate: true,
            sampledAt: microtime(true),
        );
    }

    protected function performPurge(string $queue): int
    {
        $this->client()->purgeQueue(['QueueUrl' => $this->url($queue)]);

        // PurgeQueue is asynchronous and reports nothing about how much it
        // removed. Claiming a count here would be an invention.
        return -1;
    }

    protected function purgeCooldownSeconds(): int
    {
        return self::PURGE_COOLDOWN;
    }

    protected function depthCacheSeconds(): int
    {
        $configured = $this->config['yardmaster_depth_cache'] ?? null;

        return is_numeric($configured) ? (int) $configured : 15;
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(string $queue): array
    {
        try {
            $result = $this->client()->getQueueAttributes([
                'QueueUrl' => $this->url($queue),
                'AttributeNames' => [
                    'ApproximateNumberOfMessages',
                    'ApproximateNumberOfMessagesDelayed',
                    'ApproximateNumberOfMessagesNotVisible',
                ],
            ]);
        } catch (Throwable) {
            // A queue that does not exist yet, or a throttled call. Unknown is
            // the honest answer; zero would not be.
            return [];
        }

        $attributes = $result['Attributes'] ?? [];

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * Delegate URL construction to the framework's own queue instance, so
     * prefix, suffix and FIFO naming stay in exactly one place.
     */
    protected function url(string $queue): string
    {
        return $this->queue->getQueue($queue);
    }

    protected function client(): SqsClient
    {
        return $this->queue->getSqs();
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
