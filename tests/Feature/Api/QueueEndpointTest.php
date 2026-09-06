<?php

use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Aws\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => $this->grantDashboard());

it('reports live depth per queue with the driver named', function () {
    Queue::connection('database')->pushOn('reports', new SucceedingJob);
    Queue::connection('database')->laterOn('reports', 600, new SucceedingJob);

    $queues = $this->getJson('yardmaster/api/v1/queues?connection=database')->assertOk()->json('data');

    $reports = collect($queues)->firstWhere('queue', 'reports');

    expect($reports['pending'])->toBe(1)
        ->and($reports['delayed'])->toBe(1)
        ->and($reports['driver'])->toBe('database')
        ->and($reports['approximate'])->toBeFalse();
});

it('peeks at queued jobs without consuming them', function () {
    Queue::connection('database')->pushOn('reports', new SucceedingJob);

    $jobs = $this->getJson('yardmaster/api/v1/queues/jobs?connection=database&queue=reports')
        ->assertOk()
        ->json('data');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['job_class'])->toBe(SucceedingJob::class)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('refuses an unsupported control with the reason, not a generic error', function () {
    $response = $this->getJson('yardmaster/api/v1/queues/jobs?connection=sqs&queue=reports')
        ->assertStatus(422);

    expect($response->json('capability'))->toBe('peek_payloads')
        ->and($response->json('driver'))->toBe('sqs')
        ->and($response->json('message'))->toContain('cannot peek payloads');
});

it('purges a queue and records who did it', function () {
    Queue::connection('database')->pushOn('reports', new SucceedingJob);
    Queue::connection('database')->pushOn('reports', new SucceedingJob);

    $this->postJson('yardmaster/api/v1/queues/purge', [
        'connection' => 'database',
        'queue' => 'reports',
    ])->assertOk()->assertJson(['removed' => 2]);

    $action = DB::table('yard_actions')->first();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($action->action)->toBe('purge_queue')
        ->and($action->connection)->toBe('database')
        ->and($action->queue)->toBe('reports')
        ->and((int) $action->affected)->toBe(2)
        ->and((bool) $action->succeeded)->toBeTrue();
});

it('deletes and promotes single jobs through the gate', function () {
    Queue::connection('database')->pushOn('reports', new SucceedingJob);
    Queue::connection('database')->laterOn('reports', 600, new SucceedingJob);

    $jobs = $this->getJson('yardmaster/api/v1/queues/jobs?connection=database&queue=reports')->json('data');
    $ready = collect($jobs)->firstWhere('delayed', false);
    $delayed = collect($jobs)->firstWhere('delayed', true);

    $this->postJson('yardmaster/api/v1/queues/jobs/promote', [
        'connection' => 'database', 'queue' => 'reports', 'id' => $delayed['id'],
    ])->assertOk()->assertJson(['promoted' => true]);

    $this->json('DELETE', 'yardmaster/api/v1/queues/jobs', [
        'connection' => 'database', 'queue' => 'reports', 'id' => $ready['id'],
    ])->assertOk()->assertJson(['deleted' => true]);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('yard_actions')->orderBy('id')->pluck('action')->all())
        ->toBe(['promote_delayed', 'delete_by_id']);
});

it('marks SQS depth approximate and reports its purge cooldown', function () {
    // The endpoint enumerates queues first, then reads each one's depth.
    $this->sqs->append(new Result([
        'QueueUrls' => ['https://sqs.us-east-1.amazonaws.com/123456789012/reports'],
    ]));
    $this->sqs->append(new Result([
        'Attributes' => [
            'ApproximateNumberOfMessages' => '5',
            'ApproximateNumberOfMessagesDelayed' => '1',
            'ApproximateNumberOfMessagesNotVisible' => '2',
        ],
    ]));

    $reports = collect($this->getJson('yardmaster/api/v1/queues?connection=sqs')->assertOk()->json('data'))
        ->firstWhere('queue', 'reports');

    expect($reports['pending'])->toBe(5)
        ->and($reports['total'])->toBe(8)
        ->and($reports['approximate'])->toBeTrue()
        ->and($reports['purge_cooldown'])->toBe(0);
});
