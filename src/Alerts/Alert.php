<?php

namespace Abhishek\Yardmaster\Alerts;

final class Alert
{
    public function __construct(
        public readonly AlertRule $rule,
        public readonly float $value,
        public readonly bool $firing,
        public readonly float $at,
    ) {}

    public function summary(): string
    {
        return $this->firing
            ? $this->rule->name.': '.$this->rule->describe($this->value)
            : $this->rule->name.' has recovered on '.$this->rule->target().'.';
    }
}
