<?php

namespace Iocod\Yardmaster\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Iocod\Yardmaster\Entries\RunEntry;

/**
 * @method static void record(RunEntry $entry)
 * @method static void flush()
 * @method static mixed rescue(Closure $callback)
 * @method static \Iocod\Yardmaster\Yardmaster handleExceptionsUsing(Closure $handler)
 * @method static \Iocod\Yardmaster\Yardmaster filter(Closure $filter)
 * @method static \Iocod\Yardmaster\Yardmaster tagsUsing(Closure $resolver)
 * @method static array<int, string> resolveTags(mixed $job)
 * @method static string|null currentJobUuid()
 * @method static void pauseRecording()
 * @method static void resumeRecording()
 * @method static \Iocod\Yardmaster\Buffer buffer()
 * @method static void reset()
 *
 * @see \Iocod\Yardmaster\Yardmaster
 */
class Yardmaster extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Iocod\Yardmaster\Yardmaster::class;
    }
}
