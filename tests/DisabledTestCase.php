<?php

namespace Abhishek\Yardmaster\Tests;

abstract class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('yardmaster.enabled', false);
    }
}
