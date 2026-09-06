<?php

namespace Iocod\Yardmaster;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Route;
use Iocod\Yardmaster\Actions\AuditLog;
use Iocod\Yardmaster\Commands\CheckCommand;
use Iocod\Yardmaster\Commands\RestartCommand;
use Iocod\Yardmaster\Commands\TrimCommand;
use Iocod\Yardmaster\Commands\WorkCommand;
use Iocod\Yardmaster\Contracts\Ingest;
use Iocod\Yardmaster\Drivers\AdapterManager;
use Iocod\Yardmaster\Events\ActionPerformed;
use Iocod\Yardmaster\Http\Middleware\Authorize;
use Iocod\Yardmaster\Support\Cast;
use Iocod\Yardmaster\Support\PayloadInjector;
use Iocod\Yardmaster\Support\Redactor;
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
                'create_yard_actions_table',
                'create_yard_issues_table',
                'create_yard_workers_table',
            ])
            ->hasViews('yardmaster')
            ->hasCommands([
                TrimCommand::class,
                WorkCommand::class,
                RestartCommand::class,
                CheckCommand::class,
            ]);
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
            max(1, (int) $app->make(Repository::class)->get('yardmaster.ingest.flush_threshold', 1)),
        ));

        $this->app->singleton(AdapterManager::class, fn ($app) => new AdapterManager(
            $app,
            $app->make(Repository::class),
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

            $settings = $app->make(Repository::class)
                ->get("yardmaster.ingest.drivers.{$driver}", []);

            // Passed under a distinct name so it cannot collide with the
            // config repository that other ingest drivers take.
            return $app->make($class, [
                'settings' => is_array($settings) ? $settings : [],
            ]);
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
        $this->registerAuditLog();
        $this->registerGates();
        $this->registerRoutes();
    }

    /**
     * One listener for one event, so nothing that changes queue state can be
     * audited in one place and forgotten in another.
     */
    protected function registerAuditLog(): void
    {
        $this->app->make(Dispatcher::class)->listen(
            ActionPerformed::class,
            fn (ActionPerformed $event) => $this->app->make(Yardmaster::class)->rescue(
                fn () => $this->app->make(AuditLog::class)->record($event),
            ),
        );
    }

    /**
     * Deny by default outside local development.
     *
     * An application that has not thought about who may empty its production
     * queues should not have a dashboard that lets anyone do it. Defining
     * either gate replaces this entirely.
     */
    protected function registerGates(): void
    {
        $gate = $this->app->make(Gate::class);

        foreach (['viewYardmaster', 'manageYardmaster'] as $ability) {
            if (! $gate->has($ability)) {
                $gate->define($ability, fn ($user = null) => $this->app->environment('local'));
            }
        }
    }

    protected function registerRoutes(): void
    {
        if (! $this->config()->get('yardmaster.dashboard.enabled', true)) {
            return;
        }

        Route::group([
            'domain' => $this->config()->get('yardmaster.dashboard.domain'),
            'prefix' => $this->config()->get('yardmaster.dashboard.path', 'yardmaster'),
            'middleware' => array_merge(
                (array) $this->config()->get('yardmaster.dashboard.middleware', ['web']),
                [Authorize::class],
            ),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
        });
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

        foreach (Cast::array($this->config()->get('yardmaster.recorders', [])) as $class => $config) {
            $config = Cast::array($config);

            if (! is_string($class) || ($config['enabled'] ?? true) === false) {
                continue;
            }

            $recorder = $this->app->make($class, [
                'config' => $config,
                'basePath' => $this->app->basePath(),
            ]);

            foreach ($recorder->listen ?? [] as $event) {
                $events->listen($event, fn ($e) => $recorder->record($e));
            }
        }
    }

    protected function registerPayloadInjector(): void
    {
        $injector = new PayloadInjector(
            $this->app->make(Yardmaster::class),
            Cast::array($this->config()->get('yardmaster.tags', [])),
        );

        Queue::createPayloadUsing($injector);
    }

    /**
     * Flush on the way out, and reset between Octane requests.
     *
     * The Octane hooks are registered by event name rather than class, so they
     * exist whether or not Octane is installed — and can be exercised by the
     * test suite without it.
     */
    protected function registerLifecycleHooks(): void
    {
        $this->app->terminating(function () {
            $this->app->make(Yardmaster::class)->flush();
        });

        $events = $this->app->make(Dispatcher::class);

        $events->listen('Laravel\Octane\Events\RequestReceived', function () {
            // A worker serving its second request must not inherit the first
            // one's buffer or current-job pointer. That leak shows up as jobs
            // attributed to the wrong parent: quiet, and very confusing.
            $this->app->make(Yardmaster::class)->reset();
        });

        $events->listen('Laravel\Octane\Events\RequestTerminated', function () {
            // Octane never fires the framework's terminating callbacks the way
            // a fresh process does, so the buffer is drained here instead —
            // otherwise it grows until the worker is recycled.
            $yardmaster = $this->app->make(Yardmaster::class);
            $yardmaster->flush();
            $yardmaster->reset();
        });
    }
}
