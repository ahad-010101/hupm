<?php

use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Tenant management  [WP-07, FR-REG-02, API-ADM-05…07, Q-4]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
});

function tenantPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Denise',
        'last_name' => 'Carter',
        'email' => 'denise@example.test',
        'phone' => '(404) 555-0142',
        'status' => 'active',
    ], $overrides);
}

it('creates a tenant', function () {
    $this->actingAs($this->admin)
        ->post('/admin/tenants', tenantPayload())
        ->assertRedirect();

    expect(Tenant::count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'tenant.created')->count())->toBe(1);
});

it('AC-AUTH-06 creates a tenant with no email address', function () {
    // Q-4 is unanswered and some of the 26 have no address. A tenant record must
    // stand on its own.
    $this->actingAs($this->admin)
        ->post('/admin/tenants', tenantPayload(['email' => '']))
        ->assertSessionHasNoErrors();

    $tenant = Tenant::first();

    // Stored as NULL, not an empty string — the no-login path checks for NULL,
    // and '' would quietly defeat it.
    expect($tenant->email)->toBeNull()
        ->and($tenant->isContactable())->toBeFalse()
        ->and(User::where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

it('refuses to invite a tenant with no email, and says what to do instead', function () {
    $tenant = Tenant::factory()->withoutEmail()->create();

    $this->actingAs($this->admin)
        ->post("/admin/tenants/{$tenant->id}/invite")
        ->assertSessionHasErrors('invite');

    expect(session('errors')->first('invite'))->toContain('serve them by phone');
    expect(User::count())->toBe(1); // the admin only
});

it('API-ADM-07 invites a tenant and emails a set-up link', function () {
    $tenant = Tenant::factory()->create(['email' => 'new@example.test']);

    $this->actingAs($this->admin)
        ->post("/admin/tenants/{$tenant->id}/invite")
        ->assertSessionHasNoErrors();

    $account = User::where('tenant_id', $tenant->id)->first();

    expect($account->status)->toBe(User::STATUS_INVITED)
        ->and($account->password)->toBeNull()
        ->and($account->role)->toBe(User::ROLE_TENANT);

    expect(DB::table('notification_logs')
        ->where('template', 'welcome_set_password')
        ->where('recipient', 'new@example.test')
        ->exists())->toBeTrue();
});

it('resends rather than creating a second account for an invited tenant', function () {
    $tenant = Tenant::factory()->create();

    $this->actingAs($this->admin)->post("/admin/tenants/{$tenant->id}/invite");
    $this->actingAs($this->admin)->post("/admin/tenants/{$tenant->id}/invite");

    expect(User::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(DB::table('notification_logs')->where('template', 'welcome_set_password')->count())->toBe(2);
});

it('refuses to re-invite an active account and points at password reset', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE]);

    $this->actingAs($this->admin)
        ->post("/admin/tenants/{$tenant->id}/invite")
        ->assertSessionHasErrors('invite');

    expect(session('errors')->first('invite'))->toContain('password reset');
});

it('rejects an email already used by another tenant', function () {
    Tenant::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($this->admin)
        ->post('/admin/tenants', tenantPayload(['email' => 'taken@example.test']))
        ->assertSessionHasErrors('email');
});

it('lets a tenant keep their own email when edited', function () {
    $tenant = Tenant::factory()->create(['email' => 'mine@example.test']);

    $this->actingAs($this->admin)
        ->patch("/admin/tenants/{$tenant->id}", tenantPayload(['email' => 'mine@example.test']))
        ->assertSessionHasNoErrors();
});

it('archives rather than deletes, so financial history survives', function () {
    $tenant = Tenant::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/tenants/{$tenant->id}")
        ->assertRedirect('/admin/tenants');

    // Soft delete: tenants and vendors only (DB §A4).
    expect(Tenant::count())->toBe(0)
        ->and(Tenant::withTrashed()->count())->toBe(1);
});

it('suspends the portal account when a tenant is archived', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->tenant($tenant)->create();

    $this->actingAs($this->admin)
        ->delete("/admin/tenants/{$tenant->id}")
        ->assertRedirect('/admin/tenants');

    // `users` does not soft-delete alongside `tenants`, so without this the
    // login outlives its own subject and the portal reads a null tenant on
    // every page.
    //
    // canAuthenticate() is the exact predicate LoginRequest gates on, and
    // LoginTest's "refuses a suspended account even with the right password"
    // already proves the refusal end to end — this asserts the one new link in
    // that chain: that archiving is what sets the status.
    expect($user->fresh()->status)->toBe(User::STATUS_SUSPENDED)
        ->and($user->fresh()->canAuthenticate())->toBeFalse();
});

it('refuses to archive a tenant with an active lease', function () {
    $tenant = Tenant::factory()->create();
    $unit = Unit::factory()->create();

    DB::table('leases')->insert([
        'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => 900, 'tenant_portion' => 900, 'ha_portion' => 0,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->delete("/admin/tenants/{$tenant->id}")
        ->assertSessionHasErrors('tenant');

    expect(Tenant::count())->toBe(1);
});

it('shows the whole seeded roster and flags who cannot be emailed', function () {
    $this->seed(DemoDataSeeder::class);

    $this->actingAs($this->admin)
        ->get('/admin/tenants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Tenants/Index')
            ->where('tenants.total', 26));

    expect(Tenant::whereNull('email')->count())->toBe(2);
});

it('searches by name, email and phone', function () {
    Tenant::factory()->create(['first_name' => 'Denise', 'last_name' => 'Carter', 'email' => 'd@example.test']);
    Tenant::factory()->create(['first_name' => 'Marcus', 'last_name' => 'Webb', 'email' => 'm@example.test']);

    foreach (['Carter' => 1, 'Marcus' => 1, 'd@example' => 1, 'nobody' => 0] as $term => $expected) {
        $this->actingAs($this->admin)
            ->get('/admin/tenants?search='.$term)
            ->assertInertia(fn ($page) => $page->has('tenants.data', $expected));
    }
});

it('keeps non-admins out of tenant records', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $tenant = Tenant::factory()->create();

    $this->actingAs($user)->get('/admin/tenants')->assertForbidden();
    $this->actingAs($user)->get("/admin/tenants/{$tenant->id}")->assertForbidden();
    $this->actingAs($user)->post("/admin/tenants/{$tenant->id}/invite")->assertForbidden();
})->with(['tenant', 'owner']);
