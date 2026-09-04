<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Tests\Fixtures\DispatchingJob;
use Iocod\Yardmaster\Tests\Fixtures\FailingJob;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;

beforeEach(fn () => $this->grantDashboard());

it('paginates the run log newest first', function () {
    foreach (range(1, 5) as $i) {
        SucceedingJob::dispatch("run {$i}");
    }

    $response = $this->getJson('yardmaster/api/v1/runs?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(5)
        ->and($response->json('meta.last_page'))->toBe(3)
        ->and($response->json('data.0.started_at'))
        ->toBeGreaterThanOrEqual($response->json('data.1.started_at'));
});

it('filters by status, queue and free text', function () {
    SucceedingJob::dispatch();
    FailingJob::dispatch()->onConnection('database');
    $this->work('database');

    expect($this->getJson('yardmaster/api/v1/runs?status=failed')->json('meta.total'))->toBe(1)
        ->and($this->getJson('yardmaster/api/v1/runs?connection=sync')->json('meta.total'))->toBe(1)
        ->and($this->getJson('yardmaster/api/v1/runs?search=widget+press')->json('meta.total'))->toBe(1)
        ->and($this->getJson('yardmaster/api/v1/runs?search=nothing')->json('meta.total'))->toBe(0);
});

it('shows a run with its payload, attempts and children', function () {
    DispatchingJob::dispatch();

    $parent = DB::table('yard_runs')->where('job_class', DispatchingJob::class)->first();

    $run = $this->getJson("yardmaster/api/v1/runs/{$parent->uuid}")->assertOk()->json('data');

    expect($run['job_class'])->toBe(DispatchingJob::class)
        ->and($run['payload'])->toBeArray()
        ->and($run['payload']['data'])->not->toHaveKey('command')
        ->and($run['attempts'])->toHaveCount(1)
        ->and($run['children'])->toHaveCount(1)
        ->and($run['children'][0]['job_class'])->toBe(SucceedingJob::class);
});

it('says plainly when a run has aged out rather than returning an empty body', function () {
    $this->getJson('yardmaster/api/v1/runs/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('message', 'That run has aged out of the retention window.');
});

it('offers the distinct values its filters accept', function () {
    SucceedingJob::dispatch();

    $options = $this->getJson('yardmaster/api/v1/runs/options')->assertOk()->json();

    expect($options['connections'])->toBe(['sync'])
        ->and($options['job_classes'])->toBe([SucceedingJob::class]);
});
