<?php

namespace Iocod\Yardmaster\Events;

use Iocod\Yardmaster\Alerts\Alert;

class AlertFired
{
    public function __construct(public readonly Alert $alert) {}
}
