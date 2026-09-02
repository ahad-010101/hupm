<?php

use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\TestAddressSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Property & unit registry  [WP-06, FR-REG-01]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Country/state/city are validated against the reference tables (D-19).
    $this->seed(TestAddressSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

function validProperty(array $overrides = []): array
{
    return array_merge([
        'name' => 'Peachtree House',
        'country_code' => 'US',
        'state' => 'Georgia',
        'city' => 'Atlanta',
        'street_address' => '145 Peachtree Street',
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

it('refuses to delete a property whose units have lease history', function () {
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
        ->delete("/admin/properties/{$property->id}")
        ->assertSessionHasErrors('property');

    // RESTRICT would refuse this at the database anyway; checking first turns a
    // 500 into a sentence saying what to do.
    expect(Property::find($property->id))->not->toBeNull()
        ->and(Unit::find($unit->id))->not->toBeNull();
});

it('deletes a property with no units', function () {
    $property = Property::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/properties/{$property->id}")
        ->assertRedirect('/admin/properties');

    expect(Property::find($property->id))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'property.deleted')->count())->toBe(1);
});

it('removes a property and its lease-free units together', function () {
    $property = Property::factory()->create();
    $units = Unit::factory()->count(2)->create(['property_id' => $property->id]);

    // No lease anywhere, so no unit holds a financial record and clearing them
    // by hand first would be busywork.
    $this->actingAs($this->admin)
        ->delete("/admin/properties/{$property->id}")
        ->assertRedirect('/admin/properties');

    expect(Property::find($property->id))->toBeNull()
        ->and(Unit::whereIn('id', $units->pluck('id'))->count())->toBe(0)
        // Each removal is audited separately, so the log reconstructs exactly
        // what vanished rather than only naming the property.
        ->and(DB::table('audit_logs')->where('action', 'unit.deleted')->count())->toBe(2)
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
    $this->seed(DemoDataSeeder::class);

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

/*
|--------------------------------------------------------------------------
| List ordering and column sorting  [WP-38]
|--------------------------------------------------------------------------
*/

it('WP-38 lists properties newest-added first by default', function () {
    // Distinct timestamps, inserted in a deliberately unhelpful alphabetical
    // order so "newest first" and "by name" cannot pass for one another.
    $oldest = Property::factory()->create(['name' => 'Aardvark Place', 'created_at' => now()->subDays(3)]);
    $middle = Property::factory()->create(['name' => 'Mulberry Way', 'created_at' => now()->subDays(2)]);
    $newest = Property::factory()->create(['name' => 'Zebra Court', 'created_at' => now()->subDay()]);

    $this->actingAs($this->admin)
        ->get('/admin/properties')
        ->assertInertia(fn ($page) => $page
            ->where('sort.key', 'created_at')
            ->where('sort.direction', 'desc')
            ->where('properties.data.0.id', $newest->id)
            ->where('properties.data.1.id', $middle->id)
            ->where('properties.data.2.id', $oldest->id));
});

it('WP-38 sorts properties by a whitelisted column in either direction', function () {
    Property::factory()->create(['name' => 'Aardvark Place', 'created_at' => now()->subDay()]);
    Property::factory()->create(['name' => 'Zebra Court', 'created_at' => now()->subDays(3)]);

    $this->actingAs($this->admin)
        ->get('/admin/properties?sort=name&direction=asc')
        ->assertInertia(fn ($page) => $page
            ->where('sort.key', 'name')
            ->where('properties.data.0.name', 'Aardvark Place'));

    $this->actingAs($this->admin)
        ->get('/admin/properties?sort=name&direction=desc')
        ->assertInertia(fn ($page) => $page->where('properties.data.0.name', 'Zebra Court'));
});

it('WP-38 falls back to the default when the sort key is not whitelisted', function (string $query) {
    Property::factory()->create();

    // A stale bookmark or a hand-edited URL shows the list, never a 500 — and
    // the key never reaches orderBy, which is what makes it injection-proof.
    $this->actingAs($this->admin)
        ->get('/admin/properties?'.$query)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sort.key', 'created_at'));
})->with([
    'unknown column' => 'sort=nonsense',
    'a real column that is not whitelisted' => 'sort=notes',
    'an injection attempt' => 'sort='.urlencode('name; drop table properties--'),
    'empty' => 'sort=',
]);

it('WP-38 keeps the sort when searching, and the search when sorting', function () {
    Property::factory()->create(['name' => 'Peachtree House', 'city' => 'Atlanta']);
    Property::factory()->create(['name' => 'Peachtree Lodge', 'city' => 'Atlanta']);
    Property::factory()->create(['name' => 'Elm Court', 'city' => 'Decatur']);

    $this->actingAs($this->admin)
        ->get('/admin/properties?search=Peachtree&sort=name&direction=desc')
        ->assertInertia(fn ($page) => $page
            ->has('properties.data', 2)
            ->where('sort.key', 'name')
            ->where('properties.data.0.name', 'Peachtree Lodge'));
});
