<?php

use Abhishek\Yardmaster\Drivers\AdapterManager;
use Abhishek\Yardmaster\Drivers\Capability;
use Abhishek\Yardmaster\Drivers\NullAdapter;
use Abhishek\Yardmaster\Tests\Contracts\AdapterContract;

/**
 * Tier one of the contract: run the gate assertions over every adapter the
 * package ships. None of this needs a running service, so it holds the line on
 * every machine and every CI job.
 */
it('gates every capability it does not claim', function (string $connection) {
    $adapter = app(AdapterManager::class)->for($connection);

    AdapterContract::assertGateIsHonest($adapter);
})->with(['sync', 'database', 'redis', 'sqs', 'beanstalkd']);

it('reports null rather than zero for counts it cannot make', function (string $connection) {
    $adapter = app(AdapterManager::class)->for($connection);

    AdapterContract::assertDepthMatchesCapabilities($adapter);
})->with(['sync', 'sqs', 'beanstalkd']);

it('falls back to a null adapter for a driver it does not know', function () {
    config()->set('queue.connections.exotic', ['driver' => 'kafka']);

    $adapter = app(AdapterManager::class)->for('exotic');

    expect($adapter)->toBeInstanceOf(NullAdapter::class)
        ->and($adapter->driver())->toBe('kafka')
        ->and($adapter->capabilities())->toBe([])
        ->and($adapter->depth('anything')->total())->toBeNull();
});

it('lets an application register an adapter for its own driver', function () {
    config()->set('queue.connections.exotic', ['driver' => 'kafka']);

    app(AdapterManager::class)->extend(
        'kafka',
        fn (string $connection, array $config) => new NullAdapter($connection, 'kafka-custom', $config),
    );

    expect(app(AdapterManager::class)->for('exotic')->driver())->toBe('kafka-custom');
});

it('marks destructive capabilities so they can be authorised separately', function () {
    $destructive = array_values(array_filter(
        Capability::cases(),
        fn (Capability $c) => $c->isDestructive(),
    ));

    expect($destructive)->toBe([
        Capability::DeleteById,
        Capability::PromoteDelayed,
        Capability::PurgeQueue,
    ]);
});

it('resolves an adapter for every configured connection', function () {
    $adapters = app(AdapterManager::class)->all();

    expect(array_keys($adapters))->toContain('sync', 'database', 'redis');
});
