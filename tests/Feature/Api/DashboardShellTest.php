<?php

beforeEach(fn () => $this->grantDashboard());

it('serves the app shell with the base path the client needs', function () {
    $response = $this->get('yardmaster')->assertOk();

    $response->assertSee('name="yardmaster-base" content="/yardmaster"', false)
        ->assertSee('name="yardmaster-csrf"', false)
        ->assertSee('/yardmaster/yardmaster.js', false)
        ->assertSee('id="yardmaster"', false);
});

it('hands every dashboard path the same shell so the client router owns navigation', function () {
    $this->get('yardmaster/failures')->assertOk()->assertSee('id="yardmaster"', false);
    $this->get('yardmaster/runs/anything/deep')->assertOk()->assertSee('id="yardmaster"', false);
});

it('keeps search engines out of an internal console', function () {
    $this->get('yardmaster')->assertSee('noindex', false);
});

it('serves compiled assets from inside the package', function () {
    $js = $this->get('yardmaster/yardmaster.js')->assertOk();
    $css = $this->get('yardmaster/yardmaster.css')->assertOk();

    expect($js->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($css->headers->get('Content-Type'))->toContain('text/css')
        ->and($js->headers->get('Cache-Control'))->toContain('immutable')
        ->and($js->headers->get('ETag'))->not->toBeNull();
});

it('busts the asset cache on upgrade without anyone remembering to', function () {
    $html = $this->get('yardmaster')->getContent();

    preg_match('/yardmaster\.js\?v=(\d+)/', $html, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and((int) $matches[1])->toBe(filemtime(__DIR__.'/../../../dist/yardmaster.js'));
});

it('guards the assets behind the same gate as the dashboard', function () {
    // A fresh application where no gate is granted.
    $this->refreshApplication();

    $this->get('yardmaster/yardmaster.js')->assertForbidden();
});
