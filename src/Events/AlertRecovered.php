<?php

namespace Iocod\Yardmaster\Events;

use Iocod\Yardmaster\Alerts\Alert;

class AlertRecovered
{
    public function __construct(public readonly Alert $alert) {}
}
