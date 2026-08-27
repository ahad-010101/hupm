<?php

use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Contractors  [API-ADM-38, FR-MNT-03, NG-6]
|--------------------------------------------------------------------------
|
| The maintenance screen has assigned a vendor since WP-19, from a list that
| nothing could add to — the dropdown said "No contractors on file yet" and
| always would. This is the screen that fills it.
|
| A vendor is a record, not an account. NG-6 rules out a vendor portal in v1, so
| nothing here authenticates and the email address is somewhere to send a job.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
});

/** @return array<string, mixed> */
function vendorPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ridgeline Plumbing',
        'trade' => 'Plumber',
        'phone' => '(404) 555-0119',
        'email' => 'jobs@ridgeline.test',
        'notes' => 'Out-of-hours by arrangement.',
        'active' => true,
    ], $overrides);
}

/**
 * A ticket, built directly.
 *
 * There is no MaintenanceRequest factory, and going through the service would
 * drag in a lease, a tenant and a unit for a test that only cares whether a
 * vendor has open work against them.
 */
function ticketFor(?Vendor $vendor, string $status): MaintenanceRequest
{
    $tenant = Tenant::factory()->create();
    $unit = Unit::factory()->create()->id;

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => $unit,
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00', 'tenant_portion' => '500.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
    ])->save();

    $ticket = new MaintenanceRequest;
    $ticket->forceFill([
        'ticket_number' => 'T-'.fake()->unique()->numberBetween(1000, 9999),
        'lease_id' => $lease->id,
        'tenant_id' => $tenant->id,
        'unit_id' => $unit,
        'vendor_id' => $vendor?->id,
        'category' => 'plumbing',
        'description' => 'A dripping tap.',
        'permission_to_enter' => true,
        'preferred_contact' => 'email',
        'pets_present' => false,
        'status' => $status,
    ])->save();

    return $ticket;
}

/*
 |--------------------------------------------------------------------------
 | Reaching it
 |--------------------------------------------------------------------------
 */

it('API-ADM-38 lists contractors for an admin', function () {
    Vendor::factory()->create(['name' => 'Ridgeline Plumbing', 'active' => true]);

    $this->actingAs($this->admin)
        ->get('/admin/vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Vendors/Index')
            ->has('vendors', 1)
            ->where('vendors.0.name', 'Ridgeline Plumbing'));
});

it('keeps a resident out of the contractor list', function () {
    $resident = User::factory()->create(['role' => 'tenant']);

    $this->actingAs($resident)->get('/admin/vendors')->assertForbidden();
    $this->actingAs($resident)->post('/admin/vendors', vendorPayload())->assertForbidden();
});

it('is unreachable signed out', function () {
    $this->get('/admin/vendors')->assertRedirect('/login');
});

/*
 |--------------------------------------------------------------------------
 | The gap this closes
 |--------------------------------------------------------------------------
 */

it('puts a new contractor on the maintenance assign list', function () {
    // The whole point. Before this screen existed the list could not be filled,
    // so the assign flow was built, tested and unreachable.
    $this->actingAs($this->admin)
        ->post('/admin/vendors', vendorPayload())
        ->assertSessionHasNoErrors();

    $ticket = ticketFor(null, MaintenanceRequest::STATUS_SUBMITTED);

    $this->actingAs($this->admin)
        ->get("/admin/maintenance/{$ticket->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('vendors', 1)
            ->where('vendors.0.name', 'Ridgeline Plumbing'));
});

it('leaves an unoffered contractor off the assign list without deleting them', function () {
    Vendor::factory()->create(['name' => 'Retired Roofing', 'active' => false]);

    $ticket = ticketFor(null, MaintenanceRequest::STATUS_SUBMITTED);

    $this->actingAs($this->admin)
        ->get("/admin/maintenance/{$ticket->id}")
        ->assertInertia(fn ($page) => $page->has('vendors', 0));

    // Still on file, and findable behind the filter.
    $this->actingAs($this->admin)
        ->get('/admin/vendors?inactive=1')
        ->assertInertia(fn ($page) => $page->has('vendors', 1));
});

/*
 |--------------------------------------------------------------------------
 | Adding and editing
 |--------------------------------------------------------------------------
 */

it('adds a contractor with nothing but a name', function () {
    // "Mike, boiler man" and a mobile number is a real record. A form that
    // refuses it until somebody invents a trade is a form nobody uses.
    $this->actingAs($this->admin)
        ->post('/admin/vendors', ['name' => 'Mike', 'active' => true])
        ->assertSessionHasNoErrors();

    expect(Vendor::sole()->name)->toBe('Mike');
});

it('requires a name', function () {
    $this->actingAs($this->admin)
        ->post('/admin/vendors', vendorPayload(['name' => '']))
        ->assertSessionHasErrors('name');

    expect(Vendor::count())->toBe(0);
});

it('refuses a second contractor with the same name', function () {
    // Two of these is almost always one contractor entered twice, and the
    // duplicate is discovered on the day somebody rings the wrong number.
    Vendor::factory()->create(['name' => 'Ridgeline Plumbing']);

    $this->actingAs($this->admin)
        ->post('/admin/vendors', vendorPayload())
        ->assertSessionHasErrors('name');
});

it('lets a contractor keep their own name when edited', function () {
    $vendor = Vendor::factory()->create(['name' => 'Ridgeline Plumbing']);

    $this->actingAs($this->admin)
        ->patch("/admin/vendors/{$vendor->id}", vendorPayload(['trade' => 'Plumber and heating']))
        ->assertSessionHasNoErrors();

    expect($vendor->fresh()->trade)->toBe('Plumber and heating');
});

it('reuses the name of a contractor who was removed', function () {
    $vendor = Vendor::factory()->create(['name' => 'Ridgeline Plumbing']);
    $vendor->delete();

    // Soft-deleted, so the row is still there — but the name is free again.
    $this->actingAs($this->admin)
        ->post('/admin/vendors', vendorPayload())
        ->assertSessionHasNoErrors();

    expect(Vendor::where('name', 'Ridgeline Plumbing')->count())->toBe(1);
});

it('needs an address it could actually send work to', function () {
    $this->actingAs($this->admin)
        ->post('/admin/vendors', vendorPayload(['email' => 'not-an-address']))
        ->assertSessionHasErrors('email');
});

/*
 |--------------------------------------------------------------------------
 | Removing
 |--------------------------------------------------------------------------
 */

it('refuses to remove a contractor who is mid-job', function () {
    $vendor = Vendor::factory()->create(['name' => 'Ridgeline Plumbing']);

    ticketFor($vendor, MaintenanceRequest::STATUS_IN_PROGRESS);

    $this->actingAs($this->admin)
        ->delete("/admin/vendors/{$vendor->id}")
        ->assertSessionHasErrors('vendor');

    // Deleting somebody mid-job does not cancel the job; it removes the only
    // record of who is doing it.
    expect(Vendor::find($vendor->id))->not->toBeNull();
    expect(session('errors')->first('vendor'))->toContain('1 open request');
});

it('removes a contractor whose work is all finished, but keeps them on the history', function () {
    $vendor = Vendor::factory()->create(['name' => 'Ridgeline Plumbing']);

    $ticket = ticketFor($vendor, MaintenanceRequest::STATUS_CLOSED);

    $this->actingAs($this->admin)
        ->delete("/admin/vendors/{$vendor->id}")
        ->assertSessionHasNoErrors();

    expect(Vendor::find($vendor->id))->toBeNull();

    // Soft-deleted, so last year's ticket still resolves the name rather than
    // showing a blank where a contractor should be.
    expect(Vendor::withTrashed()->find($vendor->id)->name)->toBe('Ridgeline Plumbing');
    expect($ticket->fresh()->vendor_id)->toBe($vendor->id);
});

/*
 |--------------------------------------------------------------------------
 | The record
 |--------------------------------------------------------------------------
 */

it('audits adding, editing and removing a contractor', function () {
    $this->actingAs($this->admin)->post('/admin/vendors', vendorPayload());

    $vendor = Vendor::sole();

    $this->actingAs($this->admin)
        ->patch("/admin/vendors/{$vendor->id}", vendorPayload(['phone' => '(404) 555-0200']));

    $this->actingAs($this->admin)->delete("/admin/vendors/{$vendor->id}");

    $actions = DB::table('audit_logs')->pluck('action')->all();

    foreach (['vendor.created', 'vendor.updated', 'vendor.removed'] as $action) {
        expect($actions)->toContain($action);
    }
});

/*
 |--------------------------------------------------------------------------
 | NG-6 — a record, not an account
 |--------------------------------------------------------------------------
 */

it('NG-6 creates no login for a contractor', function () {
    $before = User::count();

    $this->actingAs($this->admin)->post('/admin/vendors', vendorPayload());

    // There is no vendor portal in v1. The plumber is telephoned, not invited,
    // and an email address here is somewhere to send a job.
    expect(User::count())->toBe($before);
    expect(User::where('email', 'jobs@ridgeline.test')->exists())->toBeFalse();
});

it('has no route that would let a contractor sign in', function () {
    $vendorRoutes = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'vendor'))
        ->map(fn ($route) => $route->uri().' ['.implode(',', $route->methods()).']')
        ->values()
        ->all();

    foreach ($vendorRoutes as $route) {
        expect($route)->not->toContain('login')
            ->and($route)->not->toContain('invite')
            ->and($route)->not->toContain('password');
    }
});
