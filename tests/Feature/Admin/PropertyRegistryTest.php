<?php

use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Property & unit registry  [WP-06, FR-REG-01]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function validProperty(array $overrides = []): array
{
    return array_merge([
        'name' => 'Peachtree House',
        'street_address' => '145 Peachtree Street',
        'city' => 'Atlanta',
        'state' => 'GA',
        'postal_code' => '30303',
        'county' => 'Fulton',
    ], $overrides);
}

it('creates a property and audits it', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', validProperty())
        ->assertRedirect();

    expect(Property::where('name', 'Peachtree House')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'property.created')->count())->toBe(1);
});

it('AC-REG-02 rejects a ZIP that is not five digits', function (string $zip) {
    // ZIP drives weather-alert targeting (WP-21). "Close enough" means a
    // resident in a tornado warning never hears about it.
    $this->actingAs($this->admin)
        ->post('/admin/properties', validProperty(['postal_code' => $zip]))
        ->assertSessionHasErrors('postal_code');

    expect(Property::count())->toBe(0);
})->with(['', '303', '303033', 'ABCDE', '30303.0', '-30303', '3030 3']);

it('AC-REG-02 accepts a ZIP with a leading zero', function () {
    // 'digits:5' rather than numeric, precisely so 07001 survives as a string
    // instead of becoming 7001.
    $this->actingAs($this->admin)
        ->post('/admin/properties', validProperty(['postal_code' => '07001']))
        ->assertSessionHasNoErrors();

    expect(Property::first()->postal_code)->toBe('07001');
});

it('explains why the ZIP matters rather than just refusing it', function () {
    // UI §8: every error message says what to do next, and here it also says
    // why the field is not cosmetic.
    $this->actingAs($this->admin)
        ->post('/admin/properties', validProperty(['postal_code' => 'nope']));

    expect(session('errors')->first('postal_code'))->toContain('weather alerts');
});

it('requires the fields FR-REG-01 names', function (string $field) {
    $this->actingAs($this->admin)
        ->post('/admin/properties', validProperty([$field => '']))
        ->assertSessionHasErrors($field);
})->with(['name', 'street_address', 'city', 'postal_code']);

it('enforces the schema field lengths so a save cannot truncate silently', function () {
    $this->actingAs($this->admin)
        ->post('/admin/properties', validProperty(['name' => str_repeat('a', 151)]))
        ->assertSessionHasErrors('name');
});

it('AC-REG-01 rejects a duplicate unit number within one property', function () {
    $property = Property::factory()->create();
    Unit::factory()->create(['property_id' => $property->id, 'unit_number' => 'A']);

    $this->actingAs($this->admin)
        ->post("/admin/properties/{$property->id}/units", [
            'unit_number' => 'A',
            'status' => 'vacant',
        ])
        ->assertSessionHasErrors('unit_number');

    expect(Unit::where('property_id', $property->id)->count())->toBe(1);
});

it('AC-REG-01 gives a field-level message, not a database error', function () {
    $property = Property::factory()->create();
    Unit::factory()->create(['property_id' => $property->id, 'unit_number' => 'A']);

    $this->actingAs($this->admin)
        ->post("/admin/properties/{$property->id}/units", ['unit_number' => 'A', 'status' => 'vacant']);

    expect(session('errors')->first('unit_number'))->toBe('This property already has a unit with that number.');
});

it('AC-REG-01 allows the same unit number at a different property', function () {
    // "1" is a perfectly good unit number at twenty different addresses.
    $first = Property::factory()->create();
    $second = Property::factory()->create();
    Unit::factory()->create(['property_id' => $first->id, 'unit_number' => '1']);

    $this->actingAs($this->admin)
        ->post("/admin/properties/{$second->id}/units", ['unit_number' => '1', 'status' => 'vacant'])
        ->assertSessionHasNoErrors();

    expect(Unit::where('unit_number', '1')->count())->toBe(2);
});

it('lets a unit keep its own number when edited', function () {
    $property = Property::factory()->create();
    $unit = Unit::factory()->create(['property_id' => $property->id, 'unit_number' => 'A']);

    $this->actingAs($this->admin)
        ->patch("/admin/properties/{$property->id}/units/{$unit->id}", [
            'unit_number' => 'A',
            'status' => 'occupied',
        ])
        ->assertSessionHasNoErrors();

    expect($unit->fresh()->status)->toBe('occupied');
});

it('rejects bathrooms with more precision than the column holds', function () {
    $property = Property::factory()->create();

    // DECIMAL(3,1). 1.25 would be silently rounded by MySQL.
    $this->actingAs($this->admin)
        ->post("/admin/properties/{$property->id}/units", [
            'unit_number' => 'B', 'bathrooms' => '1.25', 'status' => 'vacant',
        ])
        ->assertSessionHasErrors('bathrooms');
});

it('refuses to delete a property that still has units', function () {
    $property = Property::factory()->create();
    Unit::factory()->create(['property_id' => $property->id]);

    $this->actingAs($this->admin)
        ->delete("/admin/properties/{$property->id}")
        ->assertSessionHasErrors('property');

    // RESTRICT would refuse this at the database anyway; checking first turns a
    // 500 into a sentence saying what to do.
    expect(Property::find($property->id))->not->toBeNull();
});

it('deletes a property with no units', function () {
    $property = Property::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/properties/{$property->id}")
        ->assertRedirect('/admin/properties');

    expect(Property::find($property->id))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'property.deleted')->count())->toBe(1);
});

it('refuses to delete a unit with lease history', function () {
    $property = Property::factory()->create();
    $unit = Unit::factory()->create(['property_id' => $property->id]);
    $tenant = Tenant::factory()->create();

    DB::table('leases')->insert([
        'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => 900, 'tenant_portion' => 300, 'ha_portion' => 600,
        'status' => 'ended', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->delete("/admin/properties/{$property->id}/units/{$unit->id}")
        ->assertSessionHasErrors('unit');

    expect(Unit::find($unit->id))->not->toBeNull();
});

it('404s when a unit is addressed through the wrong property', function () {
    $property = Property::factory()->create();
    $other = Property::factory()->create();
    $unit = Unit::factory()->create(['property_id' => $other->id]);

    // A unit id paired with someone else's property URL is a client assertion,
    // not a fact.
    $this->actingAs($this->admin)
        ->get("/admin/properties/{$property->id}/units/{$unit->id}/edit")
        ->assertNotFound();
});

it('lists the whole seeded portfolio', function () {
    $this->seed(Database\Seeders\DemoDataSeeder::class);

    $this->actingAs($this->admin)
        ->get('/admin/properties')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Properties/Index')
            // 25 properties, paginated at 25 per page.
            ->where('properties.total', 25));

    expect(Unit::count())->toBe(27);
});

it('searches by name, city and ZIP', function () {
    Property::factory()->create(['name' => 'Peachtree House', 'city' => 'Atlanta', 'postal_code' => '30303']);
    Property::factory()->create(['name' => 'Elm Court', 'city' => 'Decatur', 'postal_code' => '30030']);

    foreach (['Peachtree' => 1, 'Decatur' => 1, '30303' => 1, 'nothing' => 0] as $term => $expected) {
        $this->actingAs($this->admin)
            ->get('/admin/properties?search='.$term)
            ->assertInertia(fn ($page) => $page->has('properties.data', $expected));
    }
});

it('keeps non-admins out of the registry entirely', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $property = Property::factory()->create();

    $this->actingAs($user)->get('/admin/properties')->assertForbidden();
    $this->actingAs($user)->post('/admin/properties', validProperty())->assertForbidden();
    $this->actingAs($user)->delete("/admin/properties/{$property->id}")->assertForbidden();

    expect(Property::count())->toBe(1);
})->with(['tenant', 'owner']);
