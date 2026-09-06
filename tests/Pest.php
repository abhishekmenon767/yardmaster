<?php

use Abhishek\Yardmaster\Tests\BufferedTestCase;
use Abhishek\Yardmaster\Tests\DashboardOffTestCase;
use Abhishek\Yardmaster\Tests\DisabledTestCase;
use Abhishek\Yardmaster\Tests\PayloadsDisabledTestCase;
use Abhishek\Yardmaster\Tests\SampledTestCase;
use Abhishek\Yardmaster\Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(PayloadsDisabledTestCase::class)->in('Isolated/PayloadsDisabled');
uses(DisabledTestCase::class)->in('Isolated/Disabled');
uses(DashboardOffTestCase::class)->in('Isolated/DashboardOff');
uses(TestCase::class)->in('Isolated/NotMigrated');
uses(SampledTestCase::class)->in('Isolated/Sampled');
uses(BufferedTestCase::class)->in('Isolated/Buffered');
