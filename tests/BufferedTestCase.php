<?php

namespace Iocod\Yardmaster\Tests;

/**
 * An application that has traded a little durability for throughput by letting
 * entries accumulate before the aggregate write.
 */
abstract class BufferedTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('yardmaster.ingest.flush_threshold', 50);
    }
}
