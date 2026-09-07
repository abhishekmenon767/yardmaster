<?php

use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->grantDashboard());

/**
 * Pause is the one control that works identically on every driver, because the
 * framework owns it: the worker checks a cache key before reserving, whatever
 * it is reserving from. Yardmaster wraps and audits it rather than
 * reimplementing it.
 */
it('pauses and resumes a queue on any driver', function () {
    $this->postJson('yardmaster/api/v1/queues/pause', [
        'connection' => 'sqs', 'queue' => 'reports',
    ])->assertOk()->assertJson(['paused' => true]);

    expect(Queue::isPaused('sqs', 'reports'))->toBeTrue();

    $this->postJson('yardmaster/api/v1/queues/resume', [
        'connection' => 'sqs', 'queue' => 'reports',
    ])->assertOk()->assertJson(['paused' => false]);

    expect(Queue::isPaused('sqs', 'reports'))->toBeFalse();
});

it('stops a worker picking anything up while paused', function () {
    Queue::connection('database')->pushOn('reports', new SucceedingJob);

    $this->postJson('yardmaster/api/v1/queues/pause', [
        'connection' => 'database', 'queue' => 'reports',
    ])->assertOk();

    $this->work('database', 'reports');

    // The job is still there, untouched.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('yard_runs')->count())->toBe(0);
});

it('shows a paused queue as paused in the dashboard', function () {
    Queue::connection('database')->pushOn('reports', new SucceedingJob);

    $this->postJson('yardmaster/api/v1/queues/pause', [
        'connection' => 'database', 'queue' => 'reports',
    ])->assertOk();

    $reports = collect($this->getJson('yardmaster/api/v1/queues?connection=database')->json('data'))
        ->firstWhere('queue', 'reports');

    expect($reports['paused'])->toBeTrue();
});

it('accepts a pause that expires on its own', function () {
    $this->postJson('yardmaster/api/v1/queues/pause', [
        'connection' => 'database', 'queue' => 'reports', 'ttl' => 300,
    ])->assertOk()->assertJson(['paused' => true, 'ttl' => 300]);

    expect(Queue::isPaused('database', 'reports'))->toBeTrue();
});

it('records who paused what', function () {
    $this->postJson('yardmaster/api/v1/queues/pause', [
        'connection' => 'database', 'queue' => 'reports',
    ])->assertOk();
    $this->postJson('yardmaster/api/v1/queues/resume', [
        'connection' => 'database', 'queue' => 'reports',
    ])->assertOk();

    expect(DB::table('yard_actions')->orderBy('id')->pluck('action')->all())
        ->toBe(['pause_queue', 'resume_queue']);
});

it('requires the manage gate to pause', function () {
    $this->grantDashboard(manage: false);

    $this->postJson('yardmaster/api/v1/queues/pause', [
        'connection' => 'database', 'queue' => 'reports',
    ])->assertForbidden();
});

/**
 * Pausing arrived in Laravel v12.40.0, and the package supports ^12.0.
 *
 * On older releases QueueManager exists but the methods do not, so an
 * instanceof check passes and __call forwards to the connection — which is
 * how `GET /queues` came to die with "undefined method
 * DatabaseQueue::isPaused()" rather than simply omitting the control.
 *
 * A factory without the methods stands in for that framework here.
 */
it('does not fatal listing queues when the framework cannot pause', function () {
    $this->app->bind(QueueFactory::class, fn () => new class implements QueueFactory
    {
        public function connection($name = null)
        {
            return Queue::connection($name);
        }
    });

    Queue::connection('database')->pushOn('reports', new SucceedingJob);

    $response = $this->getJson('yardmaster/api/v1/queues')->assertOk();

    expect($response->json('data'))->not->toBeEmpty()
        ->and(collect($response->json('data'))->pluck('paused')->unique()->all())->toBe([false]);
});

it('refuses to pause rather than reporting success it cannot deliver', function () {
    $this->app->bind(QueueFactory::class, fn () => new class implements QueueFactory
    {
        public function connection($name = null)
        {
            return Queue::connection($name);
        }
    });

    $this->postJson('yardmaster/api/v1/queues/pause', [
        'connection' => 'database', 'queue' => 'reports',
    ])->assertStatus(422)->assertJsonPath('capability', 'pause_queue');
});
