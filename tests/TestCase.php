<?php

namespace Iocod\Yardmaster\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Iocod\Yardmaster\YardmasterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
        $app['config']->set('queue.default', 'sync');

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
        foreach (['create_yard_runs_table', 'create_yard_buckets_table'] as $name) {
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
