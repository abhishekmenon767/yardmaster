<?php

namespace Abhishek\Yardmaster\Alerts;

use Abhishek\Yardmaster\Drivers\AdapterManager;
use Abhishek\Yardmaster\Drivers\Capability;
use Abhishek\Yardmaster\Repositories\MetricsRepository;
use Abhishek\Yardmaster\Support\Cast;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Decides what is worth saying, and — more importantly — what is not.
 *
 * State lives in the cache between runs, so a rule has to breach for several
 * consecutive checks before it fires, has to fall back past a lower threshold
 * before it clears, and stays quiet for a cooldown once it has spoken.
 */
class AlertEvaluator
{
    public function __construct(
        protected Config $config,
        protected Cache $cache,
        protected AdapterManager $adapters,
        protected MetricsRepository $metrics,
    ) {}

    /**
     * @return array<int, Alert>
     */
    public function evaluate(): array
    {
        $alerts = [];

        foreach ($this->rules() as $rule) {
            $value = $this->measure($rule);

            if ($value === null) {
                // A rule whose metric this driver cannot answer is skipped, not
                // treated as zero. Reporting an unanswerable backlog as healthy
                // is the exact failure the capability gate exists to prevent.
                continue;
            }

            $alert = $this->apply($rule, $value);

            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * @return array<int, AlertRule>
     */
    public function rules(): array
    {
        $configured = $this->config->get('yardmaster.alerts.rules', []);
        $rules = [];

        foreach (Cast::array($configured) as $rule) {
            if (is_array($rule) && ($parsed = AlertRule::fromArray(Cast::array($rule))) !== null) {
                $rules[] = $parsed;
            }
        }

        return $rules;
    }

    public function measure(AlertRule $rule): ?float
    {
        try {
            return match ($rule->metric) {
                'backlog' => $this->backlog($rule),
                'oldest_job_age' => $this->oldestAge($rule),
                'failure_rate', 'p95_runtime' => $this->fromMetrics($rule),
                default => null,
            };
        } catch (Throwable) {
            // An unreachable driver is not an alerting condition in itself, and
            // firing on it would mean every blip pages someone.
            return null;
        }
    }

    protected function apply(AlertRule $rule, float $value): ?Alert
    {
        $state = $this->cache->get($rule->key(), ['breaches' => 0, 'firing' => false, 'fired_at' => 0.0]);
        $state = is_array($state) ? $state : ['breaches' => 0, 'firing' => false, 'fired_at' => 0.0];

        $now = microtime(true);
        $firing = (bool) ($state['firing'] ?? false);
        $breaching = $value > $rule->above;

        if ($firing) {
            // Recovery uses the lower threshold, so a value hovering on the
            // line does not alternate between alert and all-clear.
            if ($value < $rule->clearsAt()) {
                $this->cache->put($rule->key(), ['breaches' => 0, 'firing' => false, 'fired_at' => 0.0], 86400);

                return new Alert($rule, $value, firing: false, at: $now);
            }

            $this->cache->put($rule->key(), $state, 86400);

            return null;
        }

        $breaches = $breaching ? Cast::int($state['breaches'] ?? 0) + 1 : 0;

        if (! $breaching || $breaches < $rule->for) {
            $this->cache->put($rule->key(), ['breaches' => $breaches, 'firing' => false, 'fired_at' => 0.0], 86400);

            return null;
        }

        if ($rule->cooldown > 0 && $now - Cast::float(($state['fired_at'] ?? 0) < $rule->cooldown)) {
            return null;
        }

        $this->cache->put($rule->key(), ['breaches' => $breaches, 'firing' => true, 'fired_at' => $now], 86400);

        return new Alert($rule, $value, firing: true, at: $now);
    }

    protected function backlog(AlertRule $rule): ?float
    {
        $total = null;

        foreach ($this->targets($rule) as [$connection, $queue]) {
            $adapter = $this->adapters->for($connection);

            if (! $adapter->supports(Capability::CountPending)) {
                continue;
            }

            $pending = $adapter->depth($queue)->pending;

            if ($pending !== null) {
                $total = ($total ?? 0) + $pending;
            }
        }

        return $total === null ? null : (float) $total;
    }

    protected function oldestAge(AlertRule $rule): ?float
    {
        $oldest = null;

        foreach ($this->targets($rule) as [$connection, $queue]) {
            $adapter = $this->adapters->for($connection);

            if (! $adapter->supports(Capability::OldestJobAge)) {
                continue;
            }

            $age = $adapter->oldestPendingAge($queue);

            if ($age !== null) {
                $oldest = max($oldest ?? 0, $age);
            }
        }

        return $oldest === null ? null : (float) $oldest;
    }

    protected function fromMetrics(AlertRule $rule): ?float
    {
        $to = time();
        $summary = $this->metrics->summary($to - $rule->window, $to, array_filter([
            'connection' => $rule->connection,
            'queue' => $rule->queue,
        ], static fn ($value) => is_string($value) && $value !== ''));

        // Nothing ran in the window: a failure rate over no jobs is not zero,
        // it is undefined, and alerting on it would fire every quiet night.
        if (($summary['total'] ?? 0) === 0) {
            return null;
        }

        return $rule->metric === 'failure_rate'
            ? Cast::float($summary['failure_rate'])
            : Cast::float(Cast::array($summary['runtime_ms'] ?? [])['p95'] ?? 0);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    protected function targets(AlertRule $rule): array
    {
        if ($rule->connection !== null && $rule->queue !== null) {
            return [[$rule->connection, $rule->queue]];
        }

        $targets = [];

        foreach ($this->metrics->knownQueues(time() - 86400) as $seen) {
            if ($rule->connection !== null && $seen['connection'] !== $rule->connection) {
                continue;
            }

            if ($rule->queue !== null && $seen['queue'] !== $rule->queue) {
                continue;
            }

            $targets[] = [$seen['connection'], $seen['queue']];
        }

        return $targets;
    }
}
