<?php

use Iocod\Yardmaster\Drivers\Capability;

beforeEach(fn () => $this->grantDashboard());

/**
 * The dashboard renders its controls from this response and never infers a
 * capability from a driver name. These assertions are what make that safe.
 */
it('reports what each connection can actually do', function () {
    $meta = $this->getJson('yardmaster/api/v1/meta')->assertOk()->json();

    $byName = collect($meta['connections'])->keyBy('name');

    expect($byName['database']['driver'])->toBe('database')
        ->and($byName['database']['capabilities'][Capability::PeekPayloads->value]['supported'])->toBeTrue()
        ->and($byName['sqs']['capabilities'][Capability::PeekPayloads->value]['supported'])->toBeFalse()
        ->and($byName['sync']['capabilities'][Capability::PurgeQueue->value]['supported'])->toBeFalse();
});

it('ships the reason a control is unavailable so the UI need not invent wording', function () {
    $meta = $this->getJson('yardmaster/api/v1/meta')->json();

    $sqs = collect($meta['connections'])->firstWhere('name', 'sqs');

    expect($sqs['capabilities'][Capability::PeekPayloads->value]['reason'])
        ->toBe('The sqs driver cannot peek payloads.')
        ->and($sqs['capabilities'][Capability::PurgeQueue->value]['reason'])->toBeNull();
});

it('tells the client which gates this viewer holds', function () {
    $this->grantDashboard(manage: false);

    $meta = $this->getJson('yardmaster/api/v1/meta')->json();

    expect($meta['can']['view'])->toBeTrue()
        ->and($meta['can']['manage'])->toBeFalse();
});

it('marks destructive capabilities for the client', function () {
    $meta = $this->getJson('yardmaster/api/v1/meta')->json();

    $destructive = collect($meta['capabilities'])->where('destructive', true)->pluck('value')->all();

    expect($destructive)->toBe(['delete_by_id', 'promote_delayed', 'purge_queue']);
});
