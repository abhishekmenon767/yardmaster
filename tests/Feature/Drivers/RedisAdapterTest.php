<?php

use Illuminate\Support\Facades\Queue;
use Iocod\Yardmaster\Drivers\AdapterManager;
use Iocod\Yardmaster\Drivers\Capability;
use Iocod\Yardmaster\Tests\Contracts\AdapterContract;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;
use Iocod\Yardmaster\Tests\TestCase;

beforeEach(function () {
    if (! TestCase::redisIsAvailable()) {
        $this->markTestSkipped('requires a running Redis server');
    }

    $this->flushRedis();
});

afterEach(function () {
    if (TestCase::redisIsAvailable()) {
        $this->flushRedis();
    }
});

/**
 * The same contract the database driver satisfies, against Redis' quite
 * different storage: a list for ready work, sorted sets for delayed and
 * reserved.
 */
it('satisfies the behaviour contract', function () {
    $adapter = app(AdapterManager::class)->for('redis');

    AdapterContract::assertBehaviour(
        $adapter,
        push: fn (string $queue) => Queue::connection('redis')->pushOn($queue, new SucceedingJob),
        later: fn (string $queue, int $delay) => Queue::connection('redis')
            ->laterOn($queue, $delay, new SucceedingJob),
    );
});

it('enumerates queues without tripping over their sibling structures', function () {
    Queue::connection('redis')->pushOn('alpha', new SucceedingJob);
    Queue::connection('redis')->laterOn('beta', 600, new SucceedingJob);

    $queues = app(AdapterManager::class)->for('redis')->queues();

    // beta exists only as a :delayed sorted set; alpha only as a list. Both are
    // real queues, and neither ':delayed' nor ':notify' is one.
    expect($queues)->toContain('alpha')
        ->and($queues)->toContain('beta')
        ->and($queues)->not->toContain('beta:delayed')
        ->and($queues)->not->toContain('alpha:notify');
});

it('reads the oldest pending job age from the head of the list', function () {
    Queue::connection('redis')->pushOn('contract', new SucceedingJob);

    $age = app(AdapterManager::class)->for('redis')->oldestPendingAge('contract');

    expect($age)->not->toBeNull()
        ->and($age)->toBeGreaterThanOrEqual(0)
        ->and($age)->toBeLessThan(5);
});

it('reports nothing for the oldest job age on an empty queue', function () {
    expect(app(AdapterManager::class)->for('redis')->oldestPendingAge('empty'))->toBeNull();
});

it('claims every capability', function () {
    expect(app(AdapterManager::class)->for('redis')->capabilities())
        ->toHaveCount(count(Capability::cases()));
});

it('promotes a delayed job onto the ready list where a worker will find it', function () {
    Queue::connection('redis')->laterOn('contract', 3600, new SucceedingJob);

    $adapter = app(AdapterManager::class)->for('redis');
    $job = $adapter->peek('contract')[0];

    expect($job->delayed)->toBeTrue()
        ->and($adapter->promote('contract', $job->id))->toBeTrue();

    // The proof that promotion worked is a worker being able to pop it.
    $popped = Queue::connection('redis')->pop('contract');

    expect($popped)->not->toBeNull()
        ->and($popped->payload()['uuid'])->toBe($job->uuid);
});
