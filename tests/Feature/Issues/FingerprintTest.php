<?php

use Iocod\Yardmaster\Support\Fingerprint;

it('normalises the parts of a message that vary between identical failures', function () {
    expect(Fingerprint::normalise('Order 4471 failed for ada@example.com at 10.0.0.3'))
        ->toBe('Order {n} failed for {email} at {ip}')
        ->and(Fingerprint::normalise('Job 3f2504e0-4f89-11d3-9a0c-0305e82c3301 timed out'))
        ->toBe('Job {uuid} timed out');
});

it('groups two occurrences of the same failure and separates different ones', function () {
    $a = Fingerprint::for('RuntimeException', 'Order 1 failed', 'app/Jobs/Charge.php:20');
    $b = Fingerprint::for('RuntimeException', 'Order 8823 failed', 'app/Jobs/Charge.php:20');
    $c = Fingerprint::for('RuntimeException', 'Order 1 failed', 'app/Jobs/Refund.php:44');

    expect($a)->toBe($b)
        // The same exception thrown from two places is two problems; merging
        // them would hide one of them.
        ->and($a)->not->toBe($c);
});

it('keeps only the first line, since the rest is usually a dumped trace', function () {
    expect(Fingerprint::normalise("Connection refused\n#0 /app/vendor/foo.php(12)"))
        ->toBe('Connection refused');
});

it('shortens a frame outside the application root instead of leaking the deploy path', function () {
    $frame = Fingerprint::frame(new RuntimeException('boom'), '/somewhere/else');

    expect($frame)->toStartWith('…/')
        ->and($frame)->toContain('FingerprintTest.php:')
        ->and($frame)->not->toContain('/Users/');
});

it('reports the first application frame, skipping vendor', function () {
    $frame = Fingerprint::frame(new RuntimeException('boom'), dirname(__DIR__, 3));

    expect($frame)->toContain('tests/Feature/Issues/FingerprintTest.php:');
});
