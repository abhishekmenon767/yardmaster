<?php

namespace Workbench\App;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job that does nothing, so a benchmark measures Yardmaster's overhead rather
 * than the job's own work.
 */
class BenchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $label = 'bench') {}

    public function handle(): void
    {
        //
    }
}
