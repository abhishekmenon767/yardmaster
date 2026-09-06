<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Tests\Fixtures\FailingJob;

beforeEach(fn () => $this->grantDashboard());

function failTimes(int $times): void
{
    foreach (range(1, $times) as $i) {
        FailingJob::dispatch()->onConnection('database');
        test()->work('database');
    }
}

it('renders one issue rather than one row per occurrence', function () {
    failTimes(5);

    $issues = $this->getJson('yardmaster/api/v1/issues')->assertOk()->json('data');

    // Five identical failures are one problem, and an operator needs to see it
    // as one thing they can act on.
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['occurrences'])->toBe(5)
        ->and($issues[0]['exception_class'])->toBe(RuntimeException::class)
        ->and($issues[0]['job_class'])->toBe(FailingJob::class)
        ->and($issues[0]['frame'])->toContain('FailingJob.php:')
        ->and($issues[0]['status'])->toBe('open');
});

it('tracks when a problem started and when it last happened', function () {
    failTimes(3);

    $issue = $this->getJson('yardmaster/api/v1/issues')->json('data.0');

    expect($issue['first_seen'])->toBeLessThanOrEqual($issue['last_seen'])
        ->and($issue['last_seen'])->toBeGreaterThan(time() - 60);
});

it('links every occurrence back to its issue', function () {
    failTimes(2);

    $fingerprints = DB::table('yard_runs')->whereNotNull('fingerprint')->distinct()->pluck('fingerprint');

    expect($fingerprints)->toHaveCount(1)
        ->and(DB::table('yard_runs')->where('fingerprint', $fingerprints[0])->count())->toBe(2);
});

it('retries a whole cluster in one decision', function () {
    failTimes(4);

    $fingerprint = $this->getJson('yardmaster/api/v1/issues')->json('data.0.fingerprint');

    expect(DB::table('failed_jobs')->count())->toBe(4);

    $this->postJson("yardmaster/api/v1/issues/{$fingerprint}/retry")
        ->assertOk()
        ->assertJson(['retried' => 4, 'missing' => 0]);

    expect(DB::table('jobs')->count())->toBe(4)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('lets an operator silence an expected failure', function () {
    failTimes(1);

    $fingerprint = $this->getJson('yardmaster/api/v1/issues')->json('data.0.fingerprint');

    $this->postJson("yardmaster/api/v1/issues/{$fingerprint}/status", ['status' => 'ignored'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ignored');

    // Ignored issues drop out of the default view, which is what makes the
    // default view worth looking at.
    expect($this->getJson('yardmaster/api/v1/issues')->json('data'))->toHaveCount(0)
        ->and($this->getJson('yardmaster/api/v1/issues?status=all')->json('data'))->toHaveCount(1);
});

it('reopens a resolved issue that happens again', function () {
    failTimes(1);

    $fingerprint = $this->getJson('yardmaster/api/v1/issues')->json('data.0.fingerprint');

    $this->postJson("yardmaster/api/v1/issues/{$fingerprint}/status", ['status' => 'resolved'])->assertOk();

    failTimes(1);

    // Declaring something fixed does not make it fixed. An ignored issue would
    // stay ignored — that was a decision about noise, not a claim of a fix.
    expect($this->getJson("yardmaster/api/v1/issues/{$fingerprint}")->json('data'))
        ->status->toBe('open')
        ->occurrences->toBe(2);
});

it('keeps an ignored issue quiet even when it recurs', function () {
    failTimes(1);
    $fingerprint = $this->getJson('yardmaster/api/v1/issues')->json('data.0.fingerprint');
    $this->postJson("yardmaster/api/v1/issues/{$fingerprint}/status", ['status' => 'ignored'])->assertOk();

    failTimes(1);

    expect($this->getJson("yardmaster/api/v1/issues/{$fingerprint}")->json('data.status'))->toBe('ignored');
});

it('rejects a status it does not understand', function () {
    failTimes(1);
    $fingerprint = $this->getJson('yardmaster/api/v1/issues')->json('data.0.fingerprint');

    $this->postJson("yardmaster/api/v1/issues/{$fingerprint}/status", ['status' => 'maybe'])
        ->assertStatus(422);
});

it('requires the manage gate to retry or change status', function () {
    failTimes(1);
    $fingerprint = $this->getJson('yardmaster/api/v1/issues')->json('data.0.fingerprint');

    $this->grantDashboard(manage: false);

    $this->postJson("yardmaster/api/v1/issues/{$fingerprint}/retry")->assertForbidden();
    $this->postJson("yardmaster/api/v1/issues/{$fingerprint}/status", ['status' => 'ignored'])->assertForbidden();
});
