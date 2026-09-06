<?php

use Abhishek\Yardmaster\Alerts\AlertEvaluator;
use Abhishek\Yardmaster\Events\AlertFired;
use Abhishek\Yardmaster\Events\AlertRecovered;
use Abhishek\Yardmaster\Notifications\QueueAlert;
use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function rule(array $overrides = []): void
{
    config()->set('yardmaster.alerts.enabled', true);
    config()->set('yardmaster.alerts.rules', [array_merge([
        'name' => 'Default backlog',
        'connection' => 'database',
        'queue' => 'default',
        'metric' => 'backlog',
        'above' => 3,
        'recovers_below' => 1,
        'for' => 1,
        'cooldown' => 0,
    ], $overrides)]);
}

function backlog(int $jobs): void
{
    foreach (range(1, $jobs) as $i) {
        Queue::connection('database')->pushOn('default', new SucceedingJob);
    }
}

it('says nothing while the queue is healthy', function () {
    rule();
    backlog(1);

    expect(app(AlertEvaluator::class)->evaluate())->toBe([]);
});

it('fires once the threshold is passed', function () {
    rule();
    backlog(5);

    $alerts = app(AlertEvaluator::class)->evaluate();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->firing)->toBeTrue()
        ->and($alerts[0]->summary())->toContain('has 5 jobs waiting');
});

it('stays quiet while it is already firing rather than repeating every check', function () {
    rule();
    backlog(5);

    expect(app(AlertEvaluator::class)->evaluate())->toHaveCount(1)
        // An alert that repeats every minute is an alert people filter out.
        ->and(app(AlertEvaluator::class)->evaluate())->toBe([])
        ->and(app(AlertEvaluator::class)->evaluate())->toBe([]);
});

it('does not clear until the value falls past the lower threshold', function () {
    rule();
    backlog(5);
    app(AlertEvaluator::class)->evaluate();

    // Down to 2: below the trigger of 3, but not yet below the recovery of 1.
    // Clearing here is what makes an alert flap.
    DB::table('jobs')->limit(3)->delete();
    expect(app(AlertEvaluator::class)->evaluate())->toBe([]);

    DB::table('jobs')->delete();
    $recovered = app(AlertEvaluator::class)->evaluate();

    expect($recovered)->toHaveCount(1)
        ->and($recovered[0]->firing)->toBeFalse()
        ->and($recovered[0]->summary())->toContain('has recovered');
});

it('waits for consecutive breaches before believing a spike', function () {
    rule(['for' => 3]);
    backlog(5);

    expect(app(AlertEvaluator::class)->evaluate())->toBe([])
        ->and(app(AlertEvaluator::class)->evaluate())->toBe([])
        ->and(app(AlertEvaluator::class)->evaluate())->toHaveCount(1);
});

it('resets the streak when the value dips back under', function () {
    rule(['for' => 3]);
    backlog(5);

    app(AlertEvaluator::class)->evaluate();
    DB::table('jobs')->delete();
    app(AlertEvaluator::class)->evaluate();
    backlog(5);

    // The streak restarted, so one breach is not yet three.
    expect(app(AlertEvaluator::class)->evaluate())->toBe([]);
});

it('skips a rule whose metric this driver cannot answer', function () {
    rule(['connection' => 'sqs', 'metric' => 'oldest_job_age', 'above' => 1]);

    // Treating an unanswerable metric as zero is exactly the lie the capability
    // gate exists to prevent.
    expect(app(AlertEvaluator::class)->evaluate())->toBe([]);
});

it('does not alert on a failure rate over no jobs at all', function () {
    rule(['metric' => 'failure_rate', 'above' => 0.01, 'connection' => null, 'queue' => null]);

    // A quiet night is not an incident.
    expect(app(AlertEvaluator::class)->evaluate())->toBe([]);
});

it('dispatches events and notifies through the command', function () {
    Event::fake([AlertFired::class, AlertRecovered::class]);
    Notification::fake();
    config()->set('yardmaster.alerts.notify.mail', 'ops@example.com');

    rule();
    backlog(5);

    $this->artisan('yard:check')->assertSuccessful();

    Event::assertDispatched(AlertFired::class);
    Notification::assertSentOnDemand(QueueAlert::class);
});

it('reports without notifying on a dry run', function () {
    Notification::fake();
    config()->set('yardmaster.alerts.notify.mail', 'ops@example.com');

    rule();
    backlog(5);

    $this->artisan('yard:check', ['--dry-run' => true])->assertSuccessful();

    Notification::assertNothingSent();
});

it('does nothing at all when alerting is switched off', function () {
    rule();
    config()->set('yardmaster.alerts.enabled', false);
    backlog(5);

    $this->artisan('yard:check')
        ->expectsOutputToContain('Alerting is disabled.')
        ->assertSuccessful();
});

it('ignores a rule with a metric it does not understand', function () {
    rule(['metric' => 'vibes']);

    expect(app(AlertEvaluator::class)->rules())->toBe([]);
});
