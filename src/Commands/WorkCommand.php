<?php

namespace Iocod\Yardmaster\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Iocod\Yardmaster\Contracts\Drainable;
use Iocod\Yardmaster\Contracts\Ingest;
use Iocod\Yardmaster\Ingest\DatabaseIngest;
use Iocod\Yardmaster\Yardmaster;

/**
 * Drains buffered telemetry into storage.
 *
 * Only needed when the ingest driver buffers — `database` writes as it goes and
 * needs no daemon at all. Run this under a process monitor alongside your queue
 * workers, or, where no long-lived process is possible (Vapor, Cloud Run), from
 * the scheduler every minute with --once.
 */
class WorkCommand extends Command
{
    protected $signature = 'yard:work
        {--once : Drain whatever is waiting and exit}
        {--sleep=1 : Seconds to wait when the stream is empty}
        {--chunk=200 : Batches to claim per pass}
        {--max-time=3600 : Stop after this many seconds}';

    protected $description = 'Drain buffered Yardmaster telemetry into storage';

    public function handle(
        Ingest $ingest,
        DatabaseIngest $storage,
        Yardmaster $yardmaster,
        CacheFactory $cache,
    ): int {
        if (! $ingest instanceof Drainable) {
            $this->components->info(
                'The configured ingest driver writes straight through, so there is nothing to drain.'
            );

            return self::SUCCESS;
        }

        $store = $cache->store();
        $maxTime = max(1, (int) $this->option('max-time'));

        // One drainer at a time. Two processes reading the same stream would
        // both see the same batch and write it twice, quietly doubling every
        // count on the dashboard. The lease outlives --max-time so a hard-killed
        // process frees it on its own rather than wedging the daemon forever.
        $lock = $this->acquire($store, $maxTime + 60);

        if ($lock === false) {
            $this->components->warn('Another yard:work process holds the lease.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $signal = $store->get('yardmaster:restart');
        $drained = 0;

        try {
            do {
                $batches = $ingest->read(max(1, (int) $this->option('chunk')));

                foreach ($batches as $entries) {
                    $yardmaster->rescue(fn () => $storage->ingest($entries));
                    $drained += count($entries);
                }

                if ($batches !== []) {
                    // Acknowledged only after the write succeeded or was
                    // rescued; a batch left in the stream would block every
                    // later batch behind it.
                    $ingest->forget(array_keys($batches));

                    continue;
                }

                if ($this->option('once')) {
                    break;
                }

                sleep(max(0, (int) $this->option('sleep')));
            } while ($this->shouldContinue($store, $signal, $startedAt, $maxTime));
        } finally {
            $lock?->release();
        }

        $this->components->info("Drained {$drained} attempt(s).");

        return self::SUCCESS;
    }

    protected function shouldContinue(
        CacheRepository $store,
        mixed $signal,
        float $startedAt,
        int $maxTime,
    ): bool {
        if ($this->option('once')) {
            return false;
        }

        if (microtime(true) - $startedAt >= $maxTime) {
            return false;
        }

        // A long-lived process keeps running the code it booted with, so it has
        // to be told to stand down after a deploy, exactly as a queue worker is.
        return $store->get('yardmaster:restart') === $signal;
    }

    /**
     * @return Lock|null|false Null when the store cannot lock; false when taken.
     */
    protected function acquire(CacheRepository $store, int $seconds): Lock|null|false
    {
        // The repository is not itself a lock provider — its backing store is.
        // Testing the wrapper instead of the store silently disables locking
        // altogether, which is exactly the failure this guards against.
        $backing = $store->getStore();

        if (! $backing instanceof LockProvider) {
            return null;
        }

        $lock = $backing->lock('yardmaster:work', $seconds);

        return $lock->get() ? $lock : false;
    }
}
