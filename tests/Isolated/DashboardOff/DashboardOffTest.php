<?php

use Abhishek\Yardmaster\Tests\Fixtures\SucceedingJob;
use Illuminate\Support\Facades\Gate;

it('registers no routes at all when the dashboard is switched off', function () {
    Gate::define('viewYardmaster', fn () => true);

    expect(app('router')->getRoutes()->hasNamedRoute('yardmaster.api.meta'))->toBeFalse();

    $this->getJson('yardmaster/api/v1/meta')->assertNotFound();
});

it('keeps recording telemetry with the dashboard switched off', function () {
    // The dashboard is a view onto the data, not the reason to collect it.
    SucceedingJob::dispatch();

    expect(DB::table('yard_runs')->count())->toBe(1);
});
