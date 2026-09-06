<?php

namespace Iocod\Yardmaster\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Asks every yard:work process to finish its pass and stop.
 *
 * Call this during deployment, exactly as you would `queue:restart`: a
 * long-lived drainer keeps running the code it booted with, so after a release
 * it is writing with yesterday's aggregation logic.
 */
class RestartCommand extends Command
{
    protected $signature = 'yard:restart';

    protected $description = 'Gracefully restart Yardmaster ingest workers after they finish their current pass';

    public function handle(CacheFactory $cache): int
    {
        $cache->store()->forever('yardmaster:restart', (string) microtime(true));

        $this->components->info('Broadcasting a restart signal to Yardmaster workers.');

        return self::SUCCESS;
    }
}
