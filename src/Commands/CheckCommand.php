<?php

namespace Iocod\Yardmaster\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Iocod\Yardmaster\Alerts\Alert;
use Iocod\Yardmaster\Alerts\AlertEvaluator;
use Iocod\Yardmaster\Events\AlertFired;
use Iocod\Yardmaster\Events\AlertRecovered;
use Iocod\Yardmaster\Notifications\QueueAlert;

/**
 * Evaluates the alert rules once. Put it on the scheduler every minute.
 */
class CheckCommand extends Command
{
    protected $signature = 'yard:check {--dry-run : Report what would fire without notifying}';

    protected $description = 'Evaluate Yardmaster alert rules and notify on breaches';

    public function handle(AlertEvaluator $evaluator, Config $config, Dispatcher $events): int
    {
        if (! $config->get('yardmaster.alerts.enabled', false)) {
            $this->components->info('Alerting is disabled.');

            return self::SUCCESS;
        }

        $alerts = $evaluator->evaluate();

        if ($alerts === []) {
            $this->components->info('Nothing to report.');

            return self::SUCCESS;
        }

        foreach ($alerts as $alert) {
            $this->components->{$alert->firing ? 'error' : 'info'}($alert->summary());

            if ($this->option('dry-run')) {
                continue;
            }

            // The event fires regardless of whether a channel is configured, so
            // an application can route alerts anywhere without Yardmaster
            // knowing about it.
            $events->dispatch($alert->firing ? new AlertFired($alert) : new AlertRecovered($alert));

            $this->notify($alert, $config);
        }

        return self::SUCCESS;
    }

    protected function notify(Alert $alert, Config $config): void
    {
        $mail = $config->get('yardmaster.alerts.notify.mail');

        if (is_string($mail) && $mail !== '') {
            Notification::route('mail', $mail)->notify(new QueueAlert($alert));
        }
    }
}
