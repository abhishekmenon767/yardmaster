<?php

use Iocod\Yardmaster\Support\Redactor;

it('masks matching keys at any depth', function () {
    $redactor = new Redactor(['password', 'access_token']);

    $scrubbed = $redactor->scrub([
        'user' => 'ada',
        'password' => 'hunter2',
        'nested' => [
            'stripe_access_token' => 'sk_live_123',
            'keep' => 'me',
        ],
    ]);

    expect($scrubbed['password'])->toBe('[redacted]')
        ->and($scrubbed['nested']['stripe_access_token'])->toBe('[redacted]')
        ->and($scrubbed['nested']['keep'])->toBe('me')
        ->and($scrubbed['user'])->toBe('ada');
});

it('matches keys case insensitively', function () {
    $redactor = new Redactor(['secret']);

    expect($redactor->scrub(['API_SECRET' => 'x'])['API_SECRET'])->toBe('[redacted]');
});

it('leaves the payload untouched when disabled', function () {
    $redactor = new Redactor(['password'], enabled: false);

    expect($redactor->scrub(['password' => 'hunter2'])['password'])->toBe('hunter2');
});
