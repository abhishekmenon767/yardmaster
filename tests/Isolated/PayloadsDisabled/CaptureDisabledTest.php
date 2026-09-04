<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;

it('records the attempt but stores no payload when capture is off', function () {
    SucceedingJob::dispatch('hello');

    $run = DB::table('yard_runs')->first();

    expect($run)->not->toBeNull()
        ->and($run->job_class)->toBe(SucceedingJob::class)
        ->and($run->payload)->toBeNull();
});
