<?php

namespace Abhishek\Yardmaster\Facades;

use Abhishek\Yardmaster\Entries\RunEntry;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(RunEntry $entry)
 * @method static void flush()
 * @method static mixed rescue(Closure $callback)
 * @method static \Abhishek\Yardmaster\Yardmaster handleExceptionsUsing(Closure $handler)
 * @method static \Abhishek\Yardmaster\Yardmaster filter(Closure $filter)
 * @method static \Abhishek\Yardmaster\Yardmaster tagsUsing(Closure $resolver)
 * @method static array<int, string> resolveTags(mixed $job)
 * @method static string|null currentJobUuid()
 * @method static void pauseRecording()
 * @method static void resumeRecording()
 * @method static \Abhishek\Yardmaster\Buffer buffer()
 * @method static void reset()
 *
 * @see \Abhishek\Yardmaster\Yardmaster
 */
class Yardmaster extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Abhishek\Yardmaster\Yardmaster::class;
    }
}
