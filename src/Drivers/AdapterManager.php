<?php

namespace Iocod\Yardmaster\Drivers;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\SqsQueue;

/**
 * Resolves the right adapter for a queue connection.
 *
 * A driver with no adapter resolves to NullAdapter rather than an error. That
 * is the deliberate default: an unknown driver still gets full recorded history
 * from Plane A, with every live control correctly disabled — the architecture
 * degrading exactly as designed, instead of the dashboard refusing to load.
 */
class AdapterManager
{
    /** @var array<string, QueueDriverAdapter> */
    protected array $resolved = [];

    /** @var array<string, Closure> */
    protected array $custom = [];

    public function __construct(
        protected Container $container,
        protected Config $config,
    ) {}

    /**
     * Register an adapter for a driver the package does not ship.
     *
     * The closure receives the connection name and its configuration.
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->custom[$driver] = $factory;

        unset($this->resolved[$driver]);

        return $this;
    }

    public function for(string $connection): QueueDriverAdapter
    {
        return $this->resolved[$connection] ??= $this->resolve($connection);
    }

    /**
     * Every configured queue connection name.
     *
     * @return array<int, string>
     */
    public function connections(): array
    {
        $connections = $this->config->get('queue.connections', []);

        return is_array($connections) ? array_keys($connections) : [];
    }

    /**
     * Adapters for every configured connection, keyed by connection name.
     *
     * @return array<string, QueueDriverAdapter>
     */
    public function all(): array
    {
        $adapters = [];

        foreach ($this->connections() as $connection) {
            $adapters[$connection] = $this->for($connection);
        }

        return $adapters;
    }

    protected function resolve(string $connection): QueueDriverAdapter
    {
        $config = $this->config->get("queue.connections.{$connection}");
        $config = is_array($config) ? $config : [];

        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'null';

        if (isset($this->custom[$driver])) {
            return ($this->custom[$driver])($connection, $config);
        }

        return match ($driver) {
            'database' => new DatabaseAdapter(
                connection: $connection,
                config: $config,
                db: $this->container->make(DatabaseManager::class),
                cache: $this->cache(),
                events: $this->events(),
            ),
            'redis' => new RedisAdapter(
                connection: $connection,
                config: $config,
                redis: $this->container->make(RedisFactory::class),
                cache: $this->cache(),
                events: $this->events(),
                keyPrefix: $this->redisPrefix(),
                maxScan: (int) $this->config->get('yardmaster.drivers.redis.max_scan', 10000),
            ),
            'sqs' => $this->sqsAdapter($connection, $config),
            default => new NullAdapter($connection, $driver, $config),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function sqsAdapter(string $connection, array $config): QueueDriverAdapter
    {
        $queue = $this->container->make(QueueFactory::class)->connection($connection);

        // Reuse the framework's own queue instance so credentials, prefix,
        // suffix and FIFO naming are resolved in exactly one place.
        if (! $queue instanceof SqsQueue) {
            return new NullAdapter($connection, 'sqs', $config);
        }

        return new SqsAdapter(
            connection: $connection,
            config: $config,
            queue: $queue,
            cache: $this->cache(),
            events: $this->events(),
        );
    }

    protected function redisPrefix(): string
    {
        $prefix = $this->config->get('database.redis.options.prefix', '');

        return is_string($prefix) ? $prefix : '';
    }

    protected function cache(): ?Repository
    {
        return $this->container->make(CacheFactory::class)->store();
    }

    protected function events(): ?Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }
}
