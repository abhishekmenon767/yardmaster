<?php

namespace Abhishek\Yardmaster\Events;

use Abhishek\Yardmaster\Alerts\Alert;

class AlertRecovered
{
    public function __construct(public readonly Alert $alert) {}
}
