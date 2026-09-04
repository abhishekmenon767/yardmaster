<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Tests\Fixtures\FailingJob;

beforeEach(fn () => $this->grantDashboard());

function failOne(): string
{
    FailingJob::dispatch()->onConnection('database');

    test()->work('database');

    return (string) DB::table('failed_jobs')->orderByDesc('id')->value('uuid');
}

it('lists failures with the exception that caused them', function () {
    failOne();

    $failures = $this->getJson('yardmaster/api/v1/failures')->assertOk()->json('data');

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['status'])->toBe('failed')
        ->and($failures[0]['exception_class'])->toBe(RuntimeException::class)
        ->and($failures[0]['exception_message'])->toContain('the widget press jammed');
});

it('retries a failed job back onto its own connection and queue', function () {
    $uuid = failOne();

    expect(DB::table('jobs')->count())->toBe(0);

    $this->postJson('yardmaster/api/v1/failures/retry', ['uuids' => [$uuid]])
        ->assertOk()
        ->assertJson(['retried' => 1, 'missing' => 0]);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('gives the retried job a fresh identity rather than merging its history', function () {
    $uuid = failOne();

    $this->postJson('yardmaster/api/v1/failures/retry', ['uuids' => [$uuid]])->assertOk();

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload['uuid'])->not->toBe($uuid)
        ->and($payload['retry_of'])->toBe($uuid)
        ->and($payload['attempts'])->toBe(0);
});

it('retries in bulk and reports what it could not find', function () {
    $first = failOne();
    $second = failOne();

    $this->postJson('yardmaster/api/v1/failures/retry', [
        'uuids' => [$first, $second, 'never-existed'],
    ])->assertOk()->assertJson(['retried' => 2, 'missing' => 1]);

    expect(DB::table('jobs')->count())->toBe(2);
});

it('records every retry in the audit trail', function () {
    $uuid = failOne();

    $this->postJson('yardmaster/api/v1/failures/retry', ['uuids' => [$uuid]])->assertOk();

    $action = DB::table('yard_actions')->where('action', 'retry_failed')->first();

    expect($action)->not->toBeNull()
        ->and($action->target)->toBe($uuid)
        ->and($action->connection)->toBe('database');
});

it('forgets failures without requeueing them', function () {
    $uuid = failOne();

    $this->json('DELETE', 'yardmaster/api/v1/failures', ['uuids' => [$uuid]])
        ->assertOk()
        ->assertJson(['forgotten' => 1]);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('yard_actions')->where('action', 'forget_failed')->count())->toBe(1);
});

it('reads the audit trail back', function () {
    $uuid = failOne();
    $this->postJson('yardmaster/api/v1/failures/retry', ['uuids' => [$uuid]])->assertOk();

    $actions = $this->getJson('yardmaster/api/v1/actions?action=retry_failed')->assertOk()->json('data');

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['action'])->toBe('retry_failed')
        ->and($actions[0]['succeeded'])->toBeTrue()
        ->and($actions[0]['created_at'])->toBeGreaterThan(0);
});
