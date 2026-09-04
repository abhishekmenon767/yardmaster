<?php

namespace Workbench\App;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

class DemoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $label = 'ok', public bool $explode = false) {}

    public function handle(): void
    {
        usleep(random_int(2000, 60000));

        if ($this->explode) {
            throw new RuntimeException("could not reach the billing provider for {$this->label}");
        }
    }
}
