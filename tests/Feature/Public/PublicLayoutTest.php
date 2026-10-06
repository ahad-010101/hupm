<?php

use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Public layout & navigation  [WP-05, UI §2.1]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

/*
 | The public pages render from the database now (WP-36, D-27), and
 | RefreshDatabase starts every test with an empty one. Seeded explicitly
 | here rather than through a global hook: the copy these tests assert is
 | the copy the seeder ships, and a fixture that appeared by magic would
 | make the next failure unexplainable.
 */
beforeEach(function () {
    $this->seed(ContentSeeder::class);
});

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
    $this->seed(DemoDataSeeder::class);

    $tenant = DB::table('tenants')->first();

    $this->get($route)
        ->assertDontSee($tenant->last_name)
        ->assertDontSee('tenant_portion')
        ->assertDontSee('ha_portion');
})->with(['/', '/about', '/properties', '/contact']);

/*
 |--------------------------------------------------------------------------
 | The header knows whether you are signed in  [WP-54]
 |--------------------------------------------------------------------------
 |
 | The public group keeps StartSession — D-05 drops Inertia, not the session —
 | so the header can tell, and a resident who is already signed in should not
 | be invited to log in again.
 |
 */

it('AC-PUB-06 shows an avatar and account link once signed in', function () {
    $user = App\Models\User::factory()->create(['role' => 'tenant', 'name' => 'Jane Doe']);

    $this->actingAs($user)->get('/')
        ->assertSee('My account')
        ->assertSee('JD')
        ->assertSee('/portal')
        // The invitation to log in is gone, not merely joined by an avatar.
        ->assertDontSee('Tenant Login');
});

it('AC-PUB-06 still shows the login button to a visitor', function () {
    $this->get('/')
        ->assertSee('Tenant Login')
        ->assertDontSee('My account');
});

it('AC-PUB-06 sends each role to its own landing page', function (string $role, string $home) {
    $user = App\Models\User::factory()->create(['role' => $role, 'name' => 'Sam Taylor']);

    $this->actingAs($user)->get('/')->assertSee($home);
})->with([
    ['admin', '/admin'],
    ['vendor', '/work'],
    ['housing_authority', '/agency'],
    ['tenant', '/portal'],
]);

it('AC-PUB-01 still shows no tenant name, even to a signed-in resident', function () {
    $tenant = App\Models\Tenant::factory()->create(['last_name' => 'Winterbottom']);
    $user = App\Models\User::factory()->create([
        'role' => 'tenant',
        'tenant_id' => $tenant->id,
        'name' => 'Ada Winterbottom',
    ]);

    // Initials are the whole point: the avatar identifies the viewer to
    // themselves without putting a resident's name on a public page.
    $this->actingAs($user)->get('/')
        ->assertDontSee('Winterbottom')
        ->assertSee('AW');
});

it('AC-PUB-01 ships no JavaScript to a signed-in visitor either', function () {
    $user = App\Models\User::factory()->create(['role' => 'tenant', 'name' => 'Jane Doe']);

    // The avatar must not have quietly introduced a menu that needs a bundle.
    $this->actingAs($user)->get('/')->assertDontSee('data-page', escape: false);
});
