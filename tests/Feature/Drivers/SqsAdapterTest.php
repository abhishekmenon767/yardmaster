<?php

use Abhishek\Yardmaster\Drivers\AdapterManager;
use Abhishek\Yardmaster\Drivers\Capability;
use Abhishek\Yardmaster\Exceptions\UnsupportedCapability;
use Aws\Result;

/**
 * SQS is the reason the capability gate exists. It answers roughly how many
 * messages are in each state and lets you empty a queue — and nothing else.
 * These tests pin both halves: that the little it can do is done correctly, and
 * that the rest is absent rather than faked.
 */
function attributes(int $visible, int $delayed, int $notVisible): Result
{
    return new Result([
        'Attributes' => [
            'ApproximateNumberOfMessages' => (string) $visible,
            'ApproximateNumberOfMessagesDelayed' => (string) $delayed,
            'ApproximateNumberOfMessagesNotVisible' => (string) $notVisible,
        ],
    ]);
}

it('reads a three-part depth from queue attributes', function () {
    $this->sqs->append(attributes(120, 4, 7));

    $depth = app(AdapterManager::class)->for('sqs')->depth('reports');

    expect($depth->pending)->toBe(120)
        ->and($depth->delayed)->toBe(4)
        ->and($depth->reserved)->toBe(7)
        ->and($depth->total())->toBe(131);
});

it('never presents an SQS count as exact', function () {
    $this->sqs->append(attributes(1, 0, 0));

    expect(app(AdapterManager::class)->for('sqs')->depth('reports')->approximate)->toBeTrue();
});

it('shares one poll between every viewer rather than billing per dashboard', function () {
    // Exactly one response is queued. A second API call would find the mock
    // empty and come back as nulls, so identical figures prove the cache held.
    $this->sqs->append(attributes(88, 1, 2));

    $adapter = app(AdapterManager::class)->for('sqs');

    $first = $adapter->depth('reports');
    $second = $adapter->depth('reports');

    expect($second->pending)->toBe(88)
        ->and($second->pending)->toBe($first->pending)
        ->and($second->sampledAt)->toBe($first->sampledAt);
});

it('answers unknown rather than zero when the queue cannot be reached', function () {
    $this->sqs->append(new RuntimeException('throttled'));

    $depth = app(AdapterManager::class)->for('sqs')->depth('reports');

    expect($depth->pending)->toBeNull()
        ->and($depth->delayed)->toBeNull()
        ->and($depth->total())->toBeNull()
        ->and($depth->isEmpty())->toBeFalse();
});

it('purges without inventing a count it was never told', function () {
    $this->sqs->append(new Result([]));

    // PurgeQueue is asynchronous and reports nothing about what it removed.
    expect(app(AdapterManager::class)->for('sqs')->purge('reports'))->toBe(-1);
});

it('reports the purge cooldown before the operator clicks, not after', function () {
    $adapter = app(AdapterManager::class)->for('sqs');

    expect($adapter->purgeCooldownRemaining('reports'))->toBe(0);

    $this->sqs->append(new Result([]));
    $adapter->purge('reports');

    expect($adapter->purgeCooldownRemaining('reports'))->toBeGreaterThan(55)
        ->and($adapter->purgeCooldownRemaining('reports'))->toBeLessThanOrEqual(60);
});

it('lists queues by name rather than by URL', function () {
    $this->sqs->append(new Result([
        'QueueUrls' => [
            'https://sqs.us-east-1.amazonaws.com/123456789012/reports',
            'https://sqs.us-east-1.amazonaws.com/123456789012/emails',
        ],
    ]));

    expect(app(AdapterManager::class)->for('sqs')->queues())->toBe(['emails', 'reports']);
});

it('refuses to peek, delete or promote', function () {
    $adapter = app(AdapterManager::class)->for('sqs');

    expect(fn () => $adapter->peek('reports'))->toThrow(UnsupportedCapability::class)
        ->and(fn () => $adapter->forget('reports', 'x'))->toThrow(UnsupportedCapability::class)
        ->and(fn () => $adapter->promote('reports', 'x'))->toThrow(UnsupportedCapability::class)
        ->and(fn () => $adapter->oldestPendingAge('reports'))->toThrow(UnsupportedCapability::class);
});

it('names the reason a control is unavailable', function () {
    try {
        app(AdapterManager::class)->for('sqs')->peek('reports');
    } catch (UnsupportedCapability $e) {
        expect($e->getMessage())->toBe('The [sqs] driver on connection [sqs] cannot peek payloads.')
            ->and($e->capability)->toBe(Capability::PeekPayloads);
    }
});
