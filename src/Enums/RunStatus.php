<?php

namespace Abhishek\Yardmaster\Enums;

enum RunStatus: string
{
    case Processing = 'processing';
    case Processed = 'processed';
    case Released = 'released';
    case Failed = 'failed';
    case TimedOut = 'timed_out';

    /**
     * Whether this status ends the attempt. Released attempts are terminal for
     * the attempt but not for the job, which is exactly why attempts rather
     * than jobs are the unit of storage.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Processing;
    }

    public function isFailure(): bool
    {
        return $this === self::Failed || $this === self::TimedOut;
    }
}
