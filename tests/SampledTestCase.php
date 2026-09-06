<?php

namespace Abhishek\Yardmaster\Tests;

use Abhishek\Yardmaster\Recorders\JobRuns;

abstract class SampledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('yardmaster.recorders.'.JobRuns::class.'.sample', 0.5);
    }
}
