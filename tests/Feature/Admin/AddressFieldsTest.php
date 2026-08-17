<?php

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Full postal address  [D-19]
|--------------------------------------------------------------------------
|
| The address form follows the selected country instead of assuming the United
| States. AC-REG-02's five-digit ZIP rule still applies in full — it is now
| applied because the country is the US, rather than applied to everyone.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function address(array $overrides = []): array
{
    return array_merge([
        'name' => 'Peachtree House',
        'country_code' => 'US',
        'street_address' => '145 Peachtree Street',
        'address_line_2' => 'Suite 400',
        'city' => 'Atlanta',
        'state' => 'GA',
        'postal_code' => '30303',
        'county' => 'Fulton',
    ], $overrides);
}

it('stores a full address including country and second line', function () {
    $this->actingAs($this->admin)->post('/admin/properties', address())->assertSessionHasNoErrors();

    $property = Property::first();

    expect($property->country_code)->toBe('US')
        ->and($property->address_line_2)->toBe('Suite 400')
        ->and($property->postal_code)->toBe('30303')
        ->and($property->fullAddress())->toBe('145 Peachtree Street, Suite 400, Atlanta, GA 30303');
});

it('defaults to the United States, since the portfolio is in Georgia', function () {
    expect(Property::factory()->create()->country_code)->toBe('US');
});

it('omits the country from a US address and shows it otherwise', function () {
    // Printing "US" on twenty-five Atlanta addresses is noise; its absence is
    // the signal that nothing unusual is going on.
    $us = Property::factory()->create(['country_code' => 'US', 'state' => 'GA', 'postal_code' => '30303']);
    $ca = Property::factory()->create(['country_code' => 'CA', 'state' => 'ON', 'postal_code' => 'M5H 2N2']);

    expect($us->fullAddress())->not->toContain('US')
        ->and($ca->fullAddress())->toEndWith('CA');
});

it('AC-REG-02 still enforces five digits for a US address', function (string $code) {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['postal_code' => $code]))
        ->assertSessionHasErrors('postal_code');
})->with(['303', '303033', 'ABCDE', 'M5H 2N2']);

it('AC-REG-02 keeps explaining why the code matters', function () {
    $this->actingAs($this->admin)->post('/admin/properties', address(['postal_code' => 'nope']));

    expect(session('errors')->first('postal_code'))->toContain('weather alerts');
});

it('accepts a Canadian postal code that US rules would reject', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address([
            'country_code' => 'CA', 'state' => 'ON', 'city' => 'Toronto', 'postal_code' => 'M5H 2N2',
        ]))
        ->assertSessionHasNoErrors();

    expect(Property::first()->postal_code)->toBe('M5H 2N2');
});

it('rejects a subdivision that does not belong to the country', function () {
    // "GA" is Georgia in the US and not a province of Canada.
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['country_code' => 'CA', 'state' => 'GA', 'postal_code' => 'M5H 2N2']))
        ->assertSessionHasErrors('state');
});

it('names the subdivision the way the country does', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['country_code' => 'CA', 'state' => '', 'postal_code' => 'M5H 2N2']));

    expect(session('errors')->first('state'))->toContain('Province');

    session()->forget('errors');

    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['country_code' => 'JP', 'state' => '', 'postal_code' => '100-0001', 'city' => 'Tokyo']));

    expect(session('errors')->first('state'))->toContain('Prefecture');
});

it('accepts free text where a country has no fixed subdivision list', function () {
    // The United Kingdom has none. An empty dropdown would be worse than a
    // text box, and requiring a code that does not exist would be worse still.
    $this->actingAs($this->admin)
        ->post('/admin/properties', address([
            'country_code' => 'GB', 'state' => 'Greater London',
            'city' => 'London', 'postal_code' => 'SW1A 1AA',
        ]))
        ->assertSessionHasNoErrors();

    expect(Property::first()->state)->toBe('GREATER LONDON');
});

it('rejects a country that is not a real country', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', address(['country_code' => 'ZZ']))
        ->assertSessionHasErrors('country_code');
});

it('flags that weather alerts are US only', function () {
    // The NWS covers the United States. A property outside it gets no alerts,
    // and WP-21 must be able to say so rather than look broken.
    expect(Property::factory()->create(['country_code' => 'US'])->supportsWeatherAlerts())->toBeTrue()
        ->and(Property::factory()->create(['country_code' => 'CA'])->supportsWeatherAlerts())->toBeFalse();
});

it('serves subdivisions and format rules for the form', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/admin/address/subdivisions?country=CA')
        ->assertOk();

    expect($response->json('format.administrative_area_label'))->toBe('Province')
        ->and(collect($response->json('subdivisions'))->pluck('code'))->toContain('ON');
});

it('returns an empty subdivision list rather than failing for a country with none', function () {
    $this->actingAs($this->admin)
        ->getJson('/admin/address/subdivisions?country=GB')
        ->assertOk()
        ->assertJsonPath('subdivisions', [])
        ->assertJsonPath('format.has_subdivisions', false);
});

it('refuses an unknown country on the lookup', function () {
    $this->actingAs($this->admin)->getJson('/admin/address/subdivisions?country=ZZ')->assertStatus(422);
});

it('keeps the address lookup behind admin auth', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->getJson('/admin/address/subdivisions?country=US')->assertForbidden();
})->with(['tenant', 'owner']);

it('searches by postal code', function () {
    Property::factory()->create(['postal_code' => '30303']);
    Property::factory()->create(['postal_code' => '30030']);

    $this->actingAs($this->admin)
        ->get('/admin/properties?search=30303')
        ->assertInertia(fn ($page) => $page->has('properties.data', 1));
});
