<?php

namespace Iocod\Yardmaster\Tests;

use Aws\MockHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Iocod\Yardmaster\YardmasterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Stubbed SQS transport. Every test resolves the real SqsQueue and the real
     * SqsClient — only the wire is replaced, so request construction and
     * response parsing are genuinely exercised, and no test can reach AWS or
     * stall on a network timeout.
     */
    public MockHandler $sqs;

    /**
     * Every key this suite writes carries this prefix, so cleanup can remove
     * exactly what the suite created and nothing else. A test suite that
     * flushes a developer's Redis is a test suite people stop running.
     */
    public const REDIS_PREFIX = 'yardmaster_test:';

    protected function getPackageProviders($app): array
    {
        return [YardmasterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');

        // predis, because phpredis is a compiled extension the suite cannot
        // assume. A distinct prefix keeps the suite's keys separable from
        // anything else living on the developer's Redis.
        $app['config']->set('database.redis.client', 'predis');
        $app['config']->set('database.redis.options.prefix', self::REDIS_PREFIX);
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
        ]);
        $app['config']->set('queue.default', 'sync');

        // Without this the container binds a null failed-job provider and
        // failures vanish silently — which is also worth knowing about when
        // debugging a real application.
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);

        $this->sqs = new MockHandler;

        $app['config']->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789012',
            'queue' => 'default',
            'suffix' => '',
            'region' => 'us-east-1',
            'version' => 'latest',
            'handler' => $this->sqs,
        ]);

        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ]);

        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);

        $app['config']->set('yardmaster.storage.connection', null);

        // The package's own Authorize middleware is appended separately, so
        // emptying this exercises authorisation without the session and CSRF
        // machinery of the application's web group.
        $app['config']->set('yardmaster.dashboard.middleware', []);
        $app['config']->set('yardmaster.retention.runs', '48 hours');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->runYardmasterMigrations();
        $this->createQueueTables();
    }

    /**
     * Load the package's migration stubs directly rather than publishing them,
     * so the suite exercises the same schema an application receives.
     */
    protected function runYardmasterMigrations(): void
    {
        foreach ([
            'create_yard_runs_table',
            'create_yard_buckets_table',
            'create_yard_actions_table',
        ] as $name) {
            $migration = require __DIR__."/../database/migrations/{$name}.php.stub";
            $migration->up();
        }
    }

    protected function createQueueTables(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Whether a Redis server is reachable. CI provides one; a laptop often
     * does not, and a suite that fails on a missing service teaches developers
     * to ignore red.
     */
    public static function redisIsAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        $socket = @fsockopen(
            env('REDIS_HOST', '127.0.0.1'),
            (int) env('REDIS_PORT', 6379),
            $errno,
            $errstr,
            0.25,
        );

        if ($socket !== false) {
            fclose($socket);
        }

        return $available = $socket !== false;
    }

    /**
     * Grant the dashboard gates for a test.
     *
     * Gates are only defined by the package when the application has not, so
     * defining them here is exactly what an application does.
     */
    protected function grantDashboard(bool $manage = true): static
    {
        Gate::define('viewYardmaster', fn ($user = null) => true);
        Gate::define('manageYardmaster', fn ($user = null) => $manage);

        return $this;
    }

    /**
     * Remove only the keys this suite created.
     */
    protected function flushRedis(): void
    {
        $connection = $this->app->make('redis')->connection('default');

        $cursor = 0;

        do {
            $result = $connection->scan($cursor, ['match' => self::REDIS_PREFIX.'*', 'count' => 500]);

            if (! is_array($result) || count($result) < 2) {
                break;
            }

            [$cursor, $keys] = $result;

            foreach ((array) $keys as $key) {
                // SCAN returns fully prefixed keys while ordinary commands add
                // the prefix themselves, so strip it before deleting.
                $connection->del(substr((string) $key, strlen(self::REDIS_PREFIX)));
            }
        } while ((int) $cursor !== 0);
    }

    /**
     * Drain a queue connection with a real worker, so the suite exercises the
     * same event sequence production does rather than a simulation of it.
     */
    protected function work(string $connection, string $queue = 'default'): void
    {
        $this->artisan('queue:work', [
            'connection' => $connection,
            '--queue' => $queue,
            '--once' => true,
            '--tries' => 1,
        ])->run();
    }
}
