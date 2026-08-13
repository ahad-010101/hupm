<?php

/*
|--------------------------------------------------------------------------
| Dual rendering pipeline  [WP-00L DoD, DEVIATION D-05]
|--------------------------------------------------------------------------
|
| Proves the two pipelines coexist and stay separate:
|   - public routes render Blade with no Inertia involvement
|   - application routes render React through Inertia
|
| Inertia marks its mount point with a data-page attribute carrying the full
| props payload as JSON. Its absence on a public response is the structural
| guarantee behind AC-PUB-01 — there is no shared prop bag to leak a tenant
| name, balance or lease detail, so the guarantee does not rely on anyone
| remembering to scrub it.
|
*/

it('renders the public home page in Blade without Inertia', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Tenant Login'); // UI §2.1 wording
    $response->assertDontSee('data-page', escape: false);
});

it('renders an application page through Inertia', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertSee('data-page', escape: false);
});

it('keeps HandleInertiaRequests off the public middleware group', function () {
    $middleware = app('router')->getMiddlewareGroups()['public'] ?? [];

    expect($middleware)->not->toContain(\App\Http\Middleware\HandleInertiaRequests::class);
});

it('serves the public stylesheet without loading a JavaScript bundle', function () {
    // D-05's real purpose: Emergency Maintenance Instructions (WP-18) must be
    // readable with JavaScript disabled, on a bad mobile connection.
    $html = $this->get('/')->getContent();

    expect($html)->toContain('.css');
    expect($html)->not->toContain('app.jsx');
});
