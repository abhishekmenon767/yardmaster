<?php

namespace Abhishek\Yardmaster\Events;

use Abhishek\Yardmaster\Alerts\Alert;

class AlertFired
{
    public function __construct(public readonly Alert $alert) {}
}
