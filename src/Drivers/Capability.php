<?php

namespace Abhishek\Yardmaster\Drivers;

/**
 * The operations a queue driver may or may not be able to perform.
 *
 * This enum is the package's central idea. A dashboard action is never "delete
 * this job" — it is "ask the adapter whether deleting by id is possible on this
 * connection, and render accordingly". SQS genuinely cannot list pending
 * messages or delete one by id; a dashboard that pretends otherwise lies to an
 * operator at three in the morning.
 */
enum Capability: string
{
    case CountPending = 'count_pending';
    case CountDelayed = 'count_delayed';
    case CountReserved = 'count_reserved';
    case OldestJobAge = 'oldest_job_age';
    case ListQueues = 'list_queues';
    case PeekPayloads = 'peek_payloads';
    case DeleteById = 'delete_by_id';
    case PromoteDelayed = 'promote_delayed';
    case PurgeQueue = 'purge_queue';

    /**
     * Whether exercising this capability changes the queue.
     *
     * Destructive capabilities are the ones that need a second authorisation
     * gate and an audit entry, so the distinction belongs on the capability
     * itself rather than in a list the UI has to keep in step.
     */
    public function isDestructive(): bool
    {
        return match ($this) {
            self::DeleteById, self::PromoteDelayed, self::PurgeQueue => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CountPending => 'Count pending',
            self::CountDelayed => 'Count delayed',
            self::CountReserved => 'Count reserved',
            self::OldestJobAge => 'Oldest job age',
            self::ListQueues => 'List queues',
            self::PeekPayloads => 'Peek payloads',
            self::DeleteById => 'Delete one by id',
            self::PromoteDelayed => 'Promote a delayed job',
            self::PurgeQueue => 'Purge a whole queue',
        };
    }
}
