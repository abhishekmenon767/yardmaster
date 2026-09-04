<?php

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;

it('records nothing at all when the master switch is off', function () {
    SucceedingJob::dispatch();

    expect(DB::table('yard_runs')->count())->toBe(0)
        ->and(DB::table('yard_buckets')->count())->toBe(0);
});

it('still injects nothing into payloads when disabled', function () {
    $payload = null;

    Event::listen(
        JobProcessing::class,
        function ($event) use (&$payload) {
            $payload = $event->job->payload();
        }
    );

    SucceedingJob::dispatch();

    expect($payload)->not->toBeNull()
        ->and($payload)->not->toHaveKey('yardmaster');
});
