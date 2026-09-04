<?php

namespace Iocod\Yardmaster\Tests\Contracts;

use Closure;
use Iocod\Yardmaster\Drivers\Capability;
use Iocod\Yardmaster\Drivers\QueueDriverAdapter;
use Iocod\Yardmaster\Exceptions\UnsupportedCapability;

/**
 * The shared contract every queue driver adapter must satisfy.
 *
 * Written once and run against every adapter. Tier one needs no running
 * service and asserts the gate itself: that an adapter's claims and its
 * behaviour agree, in both directions. Tier two needs the real driver and
 * asserts that the operations do what they say.
 *
 * Adding a driver means adding a class and a capability map, then running this
 * file against it. If that is ever not true, the abstraction has leaked.
 */
final class AdapterContract
{
    /**
     * Each gated capability, paired with a call that must exercise its gate.
     *
     * @return array<string, Closure(QueueDriverAdapter): mixed>
     */
    public static function gatedCalls(): array
    {
        return [
            Capability::ListQueues->value => fn (QueueDriverAdapter $a) => $a->queues(),
            Capability::OldestJobAge->value => fn (QueueDriverAdapter $a) => $a->oldestPendingAge('probe'),
            Capability::PeekPayloads->value => fn (QueueDriverAdapter $a) => $a->peek('probe'),
            Capability::DeleteById->value => fn (QueueDriverAdapter $a) => $a->forget('probe', 'nope'),
            Capability::PromoteDelayed->value => fn (QueueDriverAdapter $a) => $a->promote('probe', 'nope'),
            Capability::PurgeQueue->value => fn (QueueDriverAdapter $a) => $a->purge('probe'),
        ];
    }

    /**
     * Tier one: the adapter's declaration and its behaviour must agree.
     */
    public static function assertGateIsHonest(QueueDriverAdapter $adapter): void
    {
        $capabilities = $adapter->capabilities();

        expect($adapter->driver())->not->toBeEmpty()
            ->and($capabilities)->toBe(array_values(array_unique($capabilities, SORT_REGULAR)));

        foreach (Capability::cases() as $capability) {
            expect($adapter->supports($capability))
                ->toBe(in_array($capability, $capabilities, strict: true));
        }

        foreach (self::gatedCalls() as $value => $call) {
            $capability = Capability::from($value);

            if ($adapter->supports($capability)) {
                continue;
            }

            // An unsupported operation must refuse loudly and name itself.
            // Silently returning an empty result is how a dashboard convinces
            // an operator that a backed-up queue is idle.
            try {
                $call($adapter);
                expect(false)->toBeTrue("[{$adapter->driver()}] did not gate {$capability->value}");
            } catch (UnsupportedCapability $e) {
                expect($e->capability)->toBe($capability)
                    ->and($e->driver)->toBe($adapter->driver())
                    ->and($e->getMessage())->toContain($adapter->connectionName());
            }
        }
    }

    /**
     * Tier one: depth is ungated, so it must express ignorance as null.
     */
    public static function assertDepthMatchesCapabilities(QueueDriverAdapter $adapter): void
    {
        $depth = $adapter->depth('probe');

        expect($depth->connection)->toBe($adapter->connectionName())
            ->and($depth->queue)->toBe('probe')
            ->and($adapter->purgeCooldownRemaining('probe'))->toBeGreaterThanOrEqual(0);

        foreach ([
            Capability::CountPending->value => $depth->pending,
            Capability::CountDelayed->value => $depth->delayed,
            Capability::CountReserved->value => $depth->reserved,
        ] as $value => $count) {
            if (! $adapter->supports(Capability::from($value))) {
                // null means "cannot answer". Zero would be a claim.
                expect($count)->toBeNull("[{$adapter->driver()}] reported {$value} it does not support");
            }
        }
    }

    /**
     * Tier two: the operations actually work, against a live driver.
     *
     * @param  Closure(string $queue): void  $push  Enqueue one ready job.
     * @param  Closure(string $queue, int $delay): void  $later  Enqueue one delayed job.
     */
    public static function assertBehaviour(
        QueueDriverAdapter $adapter,
        Closure $push,
        Closure $later,
        string $queue = 'contract',
    ): void {
        expect($adapter->depth($queue)->pending)->toBe(0)
            ->and($adapter->depth($queue)->isEmpty())->toBeTrue();

        $push($queue);
        $push($queue);
        $push($queue);
        $later($queue, 3600);

        $depth = $adapter->depth($queue);

        expect($depth->pending)->toBe(3)
            ->and($depth->delayed)->toBe(1)
            ->and($depth->reserved)->toBe(0)
            ->and($depth->total())->toBe(4)
            ->and($depth->approximate)->toBeFalse();

        // Peek must show the work without consuming it.
        $peeked = $adapter->peek($queue, 10);

        expect(count($peeked))->toBe(4)
            ->and($peeked[0]->jobClass)->toContain('SucceedingJob')
            ->and($peeked[0]->uuid)->not->toBeNull()
            ->and($adapter->depth($queue)->pending)->toBe(3);

        $delayed = array_values(array_filter($peeked, fn ($job) => $job->delayed));
        $ready = array_values(array_filter($peeked, fn ($job) => ! $job->delayed));

        expect($delayed)->toHaveCount(1)
            ->and($ready)->toHaveCount(3);

        // Queues the driver can enumerate must include one it is holding work for.
        if ($adapter->supports(Capability::ListQueues)) {
            expect($adapter->queues())->toContain($queue);
        }

        // Oldest pending age is the earliest warning of a stalled worker.
        expect($adapter->oldestPendingAge($queue))->toBeGreaterThanOrEqual(0);

        // Deleting one ready job leaves the rest alone.
        expect($adapter->forget($queue, $ready[0]->id))->toBeTrue()
            ->and($adapter->depth($queue)->pending)->toBe(2);

        // Deleting the same job twice reports honestly rather than throwing.
        expect($adapter->forget($queue, $ready[0]->id))->toBeFalse();

        // Promotion moves a job from delayed to ready.
        expect($adapter->promote($queue, $delayed[0]->id))->toBeTrue();

        $depth = $adapter->depth($queue);

        expect($depth->delayed)->toBe(0)
            ->and($depth->pending)->toBe(3);

        expect($adapter->promote($queue, $delayed[0]->id))->toBeFalse();

        // Purge clears everything and reports how much it removed.
        expect($adapter->purge($queue))->toBe(3)
            ->and($adapter->depth($queue)->total())->toBe(0);
    }
}
