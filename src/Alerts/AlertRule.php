<?php

namespace Iocod\Yardmaster\Alerts;

/**
 * One thing worth being woken up for.
 *
 * Two thresholds, not one. A rule that fires and clears at the same number
 * flaps: a backlog hovering on the line sends an alert every check, and an
 * alerting system that cries wolf gets muted — after which it is worse than
 * having none, because everyone believes it is watching.
 */
final class AlertRule
{
    public const METRICS = ['backlog', 'oldest_job_age', 'failure_rate', 'p95_runtime'];

    public function __construct(
        public readonly string $name,
        public readonly string $metric,
        public readonly float $above,
        public readonly ?string $connection = null,
        public readonly ?string $queue = null,
        /** Clears when the value falls back below this. Defaults to 80% of `above`. */
        public readonly ?float $recoversBelow = null,
        /** Consecutive breaching checks required before firing. */
        public readonly int $for = 1,
        /** Seconds of quiet enforced after firing, however bad it stays. */
        public readonly int $cooldown = 900,
        /** Window in seconds for rate and latency metrics. */
        public readonly int $window = 300,
    ) {}

    /**
     * @param  array<string, mixed>  $rule
     */
    public static function fromArray(array $rule): ?self
    {
        $metric = is_string($rule['metric'] ?? null) ? $rule['metric'] : null;

        if ($metric === null || ! in_array($metric, self::METRICS, true)) {
            return null;
        }

        if (! is_numeric($rule['above'] ?? null)) {
            return null;
        }

        return new self(
            name: (string) ($rule['name'] ?? $metric),
            metric: $metric,
            above: (float) $rule['above'],
            connection: is_string($rule['connection'] ?? null) ? $rule['connection'] : null,
            queue: is_string($rule['queue'] ?? null) ? $rule['queue'] : null,
            recoversBelow: is_numeric($rule['recovers_below'] ?? null) ? (float) $rule['recovers_below'] : null,
            for: max(1, (int) ($rule['for'] ?? 1)),
            cooldown: max(0, (int) ($rule['cooldown'] ?? 900)),
            window: max(60, (int) ($rule['window'] ?? 300)),
        );
    }

    public function clearsAt(): float
    {
        return $this->recoversBelow ?? $this->above * 0.8;
    }

    public function key(): string
    {
        return 'yardmaster:alert:'.md5(implode('|', [
            $this->name, $this->metric, $this->connection ?? '*', $this->queue ?? '*',
        ]));
    }

    public function target(): string
    {
        return trim(($this->connection ?? 'any').' / '.($this->queue ?? 'any'));
    }

    public function describe(float $value): string
    {
        return match ($this->metric) {
            'backlog' => sprintf('%s has %s jobs waiting (threshold %s)', $this->target(), number_format($value), number_format($this->above)),
            'oldest_job_age' => sprintf('the oldest job on %s has waited %ds (threshold %ds)', $this->target(), $value, $this->above),
            'failure_rate' => sprintf('%.1f%% of jobs on %s are failing (threshold %.1f%%)', $value * 100, $this->target(), $this->above * 100),
            'p95_runtime' => sprintf('p95 runtime on %s is %dms (threshold %dms)', $this->target(), $value, $this->above),
            default => sprintf('%s is %s on %s', $this->metric, $value, $this->target()),
        };
    }
}
