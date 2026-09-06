<?php

namespace Iocod\Yardmaster\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Iocod\Yardmaster\Alerts\Alert;

/**
 * The message an operator actually receives.
 *
 * Deliberately not queued: an alert about the queue being broken must not be
 * delivered through the queue.
 */
class QueueAlert extends Notification
{
    use Queueable;

    public function __construct(public readonly Alert $alert) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->alert->rule->name === '' ? [] : ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(($this->alert->firing ? '[Queue alert] ' : '[Recovered] ').$this->alert->rule->name)
            ->line($this->alert->summary());

        return $this->alert->firing
            ? $message->error()->line('This will stay quiet for the rule\'s cooldown even if it gets worse.')
            : $message->line('The value has fallen back below the recovery threshold.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'rule' => $this->alert->rule->name,
            'metric' => $this->alert->rule->metric,
            'target' => $this->alert->rule->target(),
            'value' => $this->alert->value,
            'threshold' => $this->alert->rule->above,
            'firing' => $this->alert->firing,
            'summary' => $this->alert->summary(),
        ];
    }
}
