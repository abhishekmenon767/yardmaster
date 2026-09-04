<?php

namespace Iocod\Yardmaster;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Queue;
use Iocod\Yardmaster\Commands\TrimCommand;
use Iocod\Yardmaster\Contracts\Ingest;
use Iocod\Yardmaster\Support\PayloadInjector;
use Iocod\Yardmaster\Support\Redactor;
use Laravel\Octane\Events\RequestReceived;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class YardmasterServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('yardmaster')
            ->hasConfigFile('yardmaster')
            ->hasMigrations([
                'create_yard_runs_table',
                'create_yard_buckets_table',
            ])
            ->hasCommand(TrimCommand::class);
    }

    protected function config(): Repository
    {
        return $this->app->make(Repository::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Buffer::class, fn ($app) => new Buffer(
            (int) $app->make(Repository::class)->get('yardmaster.buffer_limit', 1000),
        ));

        $this->app->singleton(Yardmaster::class, fn ($app) => new Yardmaster(
            $app,
            $app->make(Buffer::class),
        ));

        $this->app->singleton(Redactor::class, fn ($app) => new Redactor(
            keys: (array) $app->make(Repository::class)->get('yardmaster.redact.keys', []),
            placeholder: (string) $app->make(Repository::class)->get('yardmaster.redact.placeholder', '[redacted]'),
            enabled: (bool) $app->make(Repository::class)->get('yardmaster.redact.enabled', true),
        ));

        $this->app->singleton(Ingest::class, function ($app) {
            $driver = $app->make(Repository::class)->get('yardmaster.ingest.driver', 'database');
            $class = $app->make(Repository::class)->get("yardmaster.ingest.drivers.{$driver}.via");

            if (! is_string($class) || ! class_exists($class)) {
                throw new \InvalidArgumentException(
                    "Yardmaster ingest driver [{$driver}] is not configured with a valid class."
                );
            }

            return $app->make($class);
        });
    }

    public function packageBooted(): void
    {
        if (! $this->config()->get('yardmaster.enabled', true)) {
            return;
        }

        $this->registerRecorders();
        $this->registerPayloadInjector();
        $this->registerLifecycleHooks();
    }

    /**
     * Wire each configured recorder to the events it declares.
     *
     * Recorders are data in the config file rather than hard-coded listeners,
     * so an application can disable one, or add its own, without touching the
     * package.
     */
    protected function registerRecorders(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ((array) $this->config()->get('yardmaster.recorders', []) as $class => $config) {
            if (! is_string($class) || ($config['enabled'] ?? true) === false) {
                continue;
            }

            $recorder = $this->app->make($class, ['config' => $config]);

            foreach ($recorder->listen ?? [] as $event) {
                $events->listen($event, fn ($e) => $recorder->record($e));
            }
        }
    }

    protected function registerPayloadInjector(): void
    {
        $injector = new PayloadInjector(
            $this->app->make(Yardmaster::class),
            (array) $this->config()->get('yardmaster.tags', []),
        );

        Queue::createPayloadUsing($injector);
    }

    /**
     * Flush on the way out, and reset between Octane requests.
     *
     * Without the Octane reset a worker serving a second request inherits the
     * first request's buffer and current-job pointer, which shows up as jobs
     * attributed to the wrong parent — a quiet, confusing bug.
     */
    protected function registerLifecycleHooks(): void
    {
        $this->app->terminating(function () {
            $this->app->make(Yardmaster::class)->flush();
        });

        if (class_exists(RequestReceived::class)) {
            $this->app->make(Dispatcher::class)->listen(
                RequestReceived::class,
                fn () => $this->app->make(Yardmaster::class)->reset(),
            );
        }
    }
}
