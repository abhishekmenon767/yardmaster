<?php

namespace Abhishek\Yardmaster\Tests;

use Abhishek\Yardmaster\Recorders\JobRuns;

/**
 * Recorders are wired once at boot from config, so proving a config switch
 * works needs an application booted with that switch already flipped.
 */
abstract class PayloadsDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('yardmaster.recorders.'.JobRuns::class.'.capture_payloads', false);
    }
}
