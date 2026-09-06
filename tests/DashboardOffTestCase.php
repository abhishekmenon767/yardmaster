<?php

namespace Abhishek\Yardmaster\Tests;

abstract class DashboardOffTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('yardmaster.dashboard.enabled', false);
    }
}
