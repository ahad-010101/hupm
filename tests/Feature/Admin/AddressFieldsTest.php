<?php

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Cascading address: country → state → city  [D-19]
|--------------------------------------------------------------------------
|
| Each level is validated against the one above it. The dropdowns enforce this
| in the browser, but a crafted POST does not go through the dropdowns — so the
| server checks that a state belongs to its country and a city to its state.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\TestAddressSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function address(array $overrides = []): array
{
    return array_merge([
        'name' => 'Peachtree House',
        'country_code' => 'US',
        'state' => 'Georgia',
        'city' => 'Atlanta',
        'street_address' => '145 Peachtree Street',
        'address_line_2' => 'Suite 400',
        'postal_code' => '30303',
        'county' => 'Fulton',
    ], $overrides);
}

it('stores a full address from the cascade', function () {
    $this->actingAs($this->admin)->post('/admin/properties', address())->assertSessionHasNoErrors();

    $property = Property::first();

    expect($property->country_code)->toBe('US')
        ->and($property->state)->toBe('Georgia')
        ->and($property->city)->toBe('Atlanta')
        ->and($property->address_line_2)->toBe('Suite 400')
        ->and($property->fullAddress())->toBe('145 Peachtree Street, Suite 400, Atlanta, Georgia 30303');
});

it('requires country, state, city, address and postal code', function (string $field) {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address([$field => '']))
        ->assertSessionHasErrors($field);

    expect(Property::count())->toBe(0);
})->with(['country_code', 'state', 'city', 'street_address', 'postal_code']);

it('rejects a state that does not belong to the chosen country', function () {
    // The dropdowns make this impossible in the browser; a POST does not use
    // the dropdowns. "Ontario, United States" must not be storable.
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['state' => 'Ontario']))
        ->assertSessionHasErrors('state');

    expect(Property::count())->toBe(0);
});

it('rejects a city that does not belong to the chosen state', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['city' => 'Toronto']))
        ->assertSessionHasErrors('city');

    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['city' => 'Los Angeles'])) // real, wrong state
        ->assertSessionHasErrors('city');

    expect(Property::count())->toBe(0);
});

it('accepts a valid Canadian address', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address([
            'country_code' => 'CA', 'state' => 'Ontario', 'city' => 'Toronto',
            'postal_code' => 'M5H 2N2',
        ]))
        ->assertSessionHasNoErrors();

    expect(Property::first()->fullAddress())->toEndWith('CA');
});

it('rejects a country that is not in the list', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['country_code' => 'ZZ']))
        ->assertSessionHasErrors('country_code');
});

it('AC-REG-02 still requires five digits in the United States', function (string $code) {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['postal_code' => $code]))
        ->assertSessionHasErrors('postal_code');
})->with(['303', '303033', 'ABCDE', 'M5H 2N2', '30303.0']);

it('AC-REG-02 keeps explaining why the code matters', function () {
    $this->actingAs($this->admin)->post('/admin/properties', address(['postal_code' => 'nope']));

    expect(session('errors')->first('postal_code'))->toContain('weather alerts');
});

it('accepts a five-digit code with a leading zero', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['postal_code' => '07001']))
        ->assertSessionHasNoErrors();

    // Stored as a string: 07001 must not become 7001.
    expect(Property::first()->postal_code)->toBe('07001');
});

it('does not police postal format outside the US', function () {
    // Formats vary far too much to guess at, and a wrong guess rejects valid
    // addresses — worse than accepting an odd one.
    $this->actingAs($this->admin)
        ->post('/admin/properties', address([
            'country_code' => 'CA', 'state' => 'Ontario', 'city' => 'Toronto',
            'postal_code' => 'M5H 2N2',
        ]))
        ->assertSessionHasNoErrors();
});

it('omits the country from a US address and shows it otherwise', function () {
    $us = Property::factory()->create(['country_code' => 'US', 'state' => 'Georgia', 'postal_code' => '30303']);
    $ca = Property::factory()->create(['country_code' => 'CA', 'state' => 'Ontario', 'city' => 'Toronto', 'postal_code' => 'M5H 2N2']);

    expect($us->fullAddress())->not->toContain('US')
        ->and($ca->fullAddress())->toEndWith('CA');
});

it('flags that weather alerts are US only', function () {
    expect(Property::factory()->create(['country_code' => 'US'])->supportsWeatherAlerts())->toBeTrue()
        ->and(Property::factory()->create(['country_code' => 'CA'])->supportsWeatherAlerts())->toBeFalse();
});

it('serves states for a country', function () {
    $response = $this->actingAs($this->admin)->getJson('/admin/address/states?country=US')->assertOk();

    expect(collect($response->json('states'))->pluck('name'))->toContain('Georgia')
        ->and(collect($response->json('states'))->pluck('name'))->not->toContain('Ontario');
});

it('serves cities for a state', function () {
    $response = $this->actingAs($this->admin)->getJson('/admin/address/cities?state=1')->assertOk();

    expect(collect($response->json('cities'))->pluck('name'))->toContain('Atlanta')
        ->and(collect($response->json('cities'))->pluck('name'))->not->toContain('Toronto');
});

it('returns an empty list rather than failing for an unknown country', function () {
    $this->actingAs($this->admin)
        ->getJson('/admin/address/states?country=ZZ')
        ->assertOk()
        ->assertJsonPath('states', []);
});

it('keeps the address lookups behind admin auth', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->getJson('/admin/address/states?country=US')->assertForbidden();
    $this->actingAs($user)->getJson('/admin/address/cities?state=1')->assertForbidden();
})->with(['tenant', 'owner']);

it('pre-loads states and cities when editing, so nothing flashes empty', function () {
    $property = Property::factory()->create(['state' => 'Georgia', 'city' => 'Atlanta']);

    $this->actingAs($this->admin)
        ->get("/admin/properties/{$property->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('countries')
            ->has('states')
            ->has('cities')
            ->where('selectedStateId', 1));
});
