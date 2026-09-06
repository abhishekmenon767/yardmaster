<?php

namespace Abhishek\Yardmaster\Enums;

enum Period: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';

    public function seconds(): int
    {
        return match ($this) {
            self::Minute => 60,
            self::Hour => 3600,
            self::Day => 86400,
        };
    }

    /**
     * Floor a unix timestamp to the start of its period.
     */
    public function floor(float|int $timestamp): int
    {
        $seconds = $this->seconds();

        return (int) (floor((int) $timestamp / $seconds) * $seconds);
    }
}
