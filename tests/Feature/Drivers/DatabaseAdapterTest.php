<?php

use Illuminate\Support\Facades\Queue;
use Iocod\Yardmaster\Drivers\AdapterManager;
use Iocod\Yardmaster\Drivers\Capability;
use Iocod\Yardmaster\Tests\Contracts\AdapterContract;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;

/**
 * The database driver is the reference implementation: everything, exactly.
 */
it('satisfies the behaviour contract', function () {
    $adapter = app(AdapterManager::class)->for('database');

    AdapterContract::assertBehaviour(
        $adapter,
        push: fn (string $queue) => Queue::connection('database')->pushOn($queue, new SucceedingJob),
        later: fn (string $queue, int $delay) => Queue::connection('database')
            ->laterOn($queue, $delay, new SucceedingJob),
    );
});

it('claims every capability', function () {
    $adapter = app(AdapterManager::class)->for('database');

    expect($adapter->capabilities())->toHaveCount(count(Capability::cases()))
        ->and($adapter->depth('any')->approximate)->toBeFalse();
});

it('will not promote a job a worker has already reserved', function () {
    Queue::connection('database')->pushOn('contract', new SucceedingJob);

    $adapter = app(AdapterManager::class)->for('database');
    $job = $adapter->peek('contract')[0];

    DB::table('jobs')->where('id', $job->id)->update(['reserved_at' => time()]);

    expect($adapter->promote('contract', $job->id))->toBeFalse();
});

it('separates delayed work from pending work', function () {
    Queue::connection('database')->laterOn('contract', 600, new SucceedingJob);

    $depth = app(AdapterManager::class)->for('database')->depth('contract');

    expect($depth->pending)->toBe(0)
        ->and($depth->delayed)->toBe(1)
        ->and($depth->isEmpty())->toBeFalse();
});
