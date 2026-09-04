<?php

use Illuminate\Support\Facades\DB;
use Iocod\Yardmaster\Tests\Fixtures\SucceedingJob;

it('removes runs past the retention window but keeps the buckets', function () {
    SucceedingJob::dispatch();

    DB::table('yard_runs')->update(['started_at' => now()->subDays(5)->timestamp]);

    $this->artisan('yard:trim')->assertSuccessful();

    expect(DB::table('yard_runs')->count())->toBe(0)
        ->and(DB::table('yard_buckets')->count())->toBeGreaterThan(0);
});

it('drops payloads well before it drops the rows that carry them', function () {
    SucceedingJob::dispatch();

    DB::table('yard_runs')->update(['started_at' => now()->subHours(12)->timestamp]);

    $this->artisan('yard:trim')->assertSuccessful();

    $run = DB::table('yard_runs')->first();

    expect($run)->not->toBeNull()
        ->and($run->payload)->toBeNull();
});

it('reports without deleting on a dry run', function () {
    SucceedingJob::dispatch();

    DB::table('yard_runs')->update(['started_at' => now()->subDays(5)->timestamp]);

    $this->artisan('yard:trim', ['--dry-run' => true])->assertSuccessful();

    expect(DB::table('yard_runs')->count())->toBe(1);
});
