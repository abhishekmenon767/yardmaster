<?php

use Iocod\Yardmaster\Tests\DashboardOffTestCase;
use Iocod\Yardmaster\Tests\DisabledTestCase;
use Iocod\Yardmaster\Tests\PayloadsDisabledTestCase;
use Iocod\Yardmaster\Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(PayloadsDisabledTestCase::class)->in('Isolated/PayloadsDisabled');
uses(DisabledTestCase::class)->in('Isolated/Disabled');
uses(DashboardOffTestCase::class)->in('Isolated/DashboardOff');
uses(TestCase::class)->in('Isolated/NotMigrated');
