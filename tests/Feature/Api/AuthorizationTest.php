<?php

/**
 * Two gates, separated on purpose. Collapsing them means anyone who can look at
 * the dashboard can empty a production queue.
 */
it('denies everything by default outside local development', function () {
    // No gate defined by the application, and the test environment is not
    // local, so the package's own default must refuse.
    $this->getJson('yardmaster/api/v1/meta')->assertForbidden();
});

it('allows reading once the view gate is granted', function () {
    $this->grantDashboard(manage: false);

    $this->getJson('yardmaster/api/v1/meta')->assertOk();
});

it('refuses destructive calls to a viewer who may not manage', function () {
    $this->grantDashboard(manage: false);

    $this->getJson('yardmaster/api/v1/queues')->assertOk();

    $this->postJson('yardmaster/api/v1/queues/purge', [
        'connection' => 'database',
        'queue' => 'default',
    ])->assertForbidden();

    $this->postJson('yardmaster/api/v1/failures/retry', ['uuids' => ['x']])->assertForbidden();
});

it('allows destructive calls once the manage gate is granted', function () {
    $this->grantDashboard();

    $this->postJson('yardmaster/api/v1/queues/purge', [
        'connection' => 'database',
        'queue' => 'default',
    ])->assertOk();
});
