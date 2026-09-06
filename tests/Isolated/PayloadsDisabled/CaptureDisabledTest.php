<?php

use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Illuminate\Support\Facades\DB;

it('records the attempt but stores no payload when capture is off', function () {
    SucceedingJob::dispatch('hello');

    $run = DB::table('yard_runs')->first();

    expect($run)->not->toBeNull()
        ->and($run->job_class)->toBe(SucceedingJob::class)
        ->and($run->payload)->toBeNull();
});
