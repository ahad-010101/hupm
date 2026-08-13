<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Public layout & navigation  [WP-05, UI §2.1]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

it('serves every public route without Inertia', function (string $route) {
    $response = $this->get($route);

    $response->assertOk();
    // AC-PUB-01 is structural: the public middleware group omits
    // HandleInertiaRequests, so there is no shared prop bag to leak from.
    $response->assertDontSee('data-page', escape: false);
})->with([
    '/', '/about', '/properties', '/resources', '/georgia-rental-info',
    '/emergency-maintenance', '/contact', '/privacy', '/terms',
]);

it('shows the full navigation and a tenant login button', function () {
    $response = $this->get('/');

    foreach (['Home', 'About', 'Properties', 'Resources', 'Contact', 'Tenant Login'] as $label) {
        $response->assertSee($label);
    }
});

it('gives the emergency maintenance number its own footer block', function () {
    // The one piece of information on the public site someone may be looking
    // for in a hurry. It is never buried in a list of links.
    $this->get('/')->assertSee('Emergency maintenance')->assertSee('call 911 first');
});

it('says so plainly when the emergency number is not configured', function () {
    // [GATE] company.emergency_phone is unset until the client supplies it.
    // A blank space where a phone number belongs is worse than an admission.
    $this->get('/')->assertSee('Number not yet configured.');
});

it('loads no JavaScript bundle on the public site', function () {
    // D-05's whole purpose: the emergency instructions must render with
    // JavaScript disabled, on a poor connection.
    $html = $this->get('/emergency-maintenance')->getContent();

    expect($html)->toContain('.css')
        ->and($html)->not->toContain('app.jsx')
        ->and($html)->not->toContain('type="module"');
});

it('AC-PUB-01 exposes no tenant data on any public route', function (string $route) {
    // Demo data exists in the database; none of it may reach a public page.
    $this->seed(Database\Seeders\DemoDataSeeder::class);

    $tenant = DB::table('tenants')->first();

    $this->get($route)
        ->assertDontSee($tenant->last_name)
        ->assertDontSee('tenant_portion')
        ->assertDontSee('ha_portion');
})->with(['/', '/about', '/properties', '/contact']);
