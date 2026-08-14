<?php

use App\Models\HousingAuthority;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Housing authorities  [WP-06, FR-REG-01, GATE Q-2]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('creates an authority defaulting to per-tenant remittance', function () {
    $this->actingAs($this->admin)
        ->post('/admin/housing-authorities', [
            'name' => 'Atlanta Housing Authority',
            'remittance_type' => 'per_tenant',
        ])
        ->assertRedirect('/admin/housing-authorities');

    expect(HousingAuthority::first()->remittance_type)->toBe('per_tenant');
});

it('GATE Q-2 accepts lump sum, which changes how payments are matched', function () {
    // Unanswered and one of the five blocking questions. A lump_sum authority
    // adds an allocation screen to WP-12 (risk R-9).
    $this->actingAs($this->admin)
        ->post('/admin/housing-authorities', [
            'name' => 'DeKalb Housing Authority',
            'remittance_type' => 'lump_sum',
        ])
        ->assertSessionHasNoErrors();

    expect(HousingAuthority::first()->remittance_type)->toBe('lump_sum');
});

it('rejects a remittance type outside the two the spec allows', function () {
    $this->actingAs($this->admin)
        ->post('/admin/housing-authorities', ['name' => 'X', 'remittance_type' => 'quarterly'])
        ->assertSessionHasErrors('remittance_type');
});

it('requires a name and validates the contact email', function () {
    $this->actingAs($this->admin)
        ->post('/admin/housing-authorities', ['name' => '', 'remittance_type' => 'per_tenant'])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->admin)
        ->post('/admin/housing-authorities', [
            'name' => 'X', 'remittance_type' => 'per_tenant', 'contact_email' => 'not-an-address',
        ])
        ->assertSessionHasErrors('contact_email');
});

it('audits a change of remittance type', function () {
    $authority = HousingAuthority::factory()->create();

    $this->actingAs($this->admin)
        ->patch("/admin/housing-authorities/{$authority->id}", [
            'name' => $authority->name,
            'remittance_type' => 'lump_sum',
        ]);

    $changes = json_decode(
        DB::table('audit_logs')->where('action', 'housing_authority.updated')->value('changes'),
        true,
    );

    expect($changes['remittance_type']['from'])->toBe('per_tenant')
        ->and($changes['remittance_type']['to'])->toBe('lump_sum');
});

it('refuses to delete an authority attached to a lease', function () {
    $authority = HousingAuthority::factory()->create();
    $unit = Unit::factory()->create();
    $tenant = Tenant::factory()->create();

    DB::table('leases')->insert([
        'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
        'housing_authority_id' => $authority->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => 900, 'tenant_portion' => 300, 'ha_portion' => 600,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->delete("/admin/housing-authorities/{$authority->id}")
        ->assertSessionHasErrors('authority');

    expect(HousingAuthority::find($authority->id))->not->toBeNull();
});

it('deletes an unused authority', function () {
    $authority = HousingAuthority::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/housing-authorities/{$authority->id}")
        ->assertRedirect('/admin/housing-authorities');

    expect(HousingAuthority::count())->toBe(0);
});

it('keeps non-admins out', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/admin/housing-authorities')->assertForbidden();
})->with(['tenant', 'owner']);
