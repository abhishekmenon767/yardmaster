<?php

use Illuminate\Support\Facades\Schema;

beforeEach(fn () => $this->grantDashboard());

it('tells an operator to migrate instead of dumping SQL at them', function () {
    Schema::drop('yard_runs');

    $response = $this->getJson('yardmaster/api/v1/queues')->assertStatus(503);

    expect($response->json('message'))->toBe('Yardmaster has not been migrated yet. Run: php artisan migrate')
        ->and($response->json('missing_tables'))->toBe(['yard_runs']);
});

it('still serves the shell so the dashboard can render that message', function () {
    Schema::drop('yard_runs');

    $this->get('yardmaster')->assertOk();
});

it('stays out of the way once the tables are there', function () {
    $this->getJson('yardmaster/api/v1/queues')->assertOk();
});

it('says the schema is out of date when a table is behind the code', function () {
    Schema::table('yard_runs', function ($table) {
        $table->dropIndex(['fingerprint', 'started_at']);
        $table->dropColumn('fingerprint');
    });

    $response = $this->getJson('yardmaster/api/v1/queues')->assertStatus(503);

    // A table that exists but is behind is worse than one that is missing:
    // jobs keep succeeding while every insert fails inside the rescue boundary,
    // and the dashboard simply goes quiet.
    expect($response->json('message'))->toBe("Yardmaster's schema is out of date. Run: php artisan migrate")
        ->and($response->json('stale_tables'))->toBe(['yard_runs']);
});
