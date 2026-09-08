<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| An admin raising a repair  [WP-46, FR-MNT-01]
|--------------------------------------------------------------------------
|
| Until now only a resident could start a ticket, so anything reported by
| telephone or found on an inspection had nowhere to go. The admin form adds
| three things a resident's cannot: a contractor, a charge, and the option to
| keep the whole thing out of their sight.
|
| The dangerous one is the last. A ticket hidden on screen but announced by
| email is not hidden.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->admin = User::factory()->admin()->create();
    $this->balances = app(BalanceCalculator::class);

    $property = Property::factory()->create(['name' => 'Peachtree House']);
    $this->tenant = Tenant::factory()->create(['phone' => '(404) 555-0101']);
    $this->resident = User::factory()->create([
        'role' => 'tenant',
        'tenant_id' => $this->tenant->id,
    ]);

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create(['property_id' => $property->id, 'unit_number' => '1'])->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '900.00',
        'tenant_portion' => '900.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();

    $this->vendor = Vendor::factory()->create(['name' => 'Mikes Plumbing', 'active' => true]);
});

function raisePayload(array $overrides = []): array
{
    return array_merge([
        'lease_id' => test()->lease->id,
        'category' => 'plumbing',
        'description' => 'Leaking kitchen tap, reported by telephone.',
        'is_emergency' => false,
        'internal' => false,
        'permission_to_enter' => false,
        'preferred_contact' => 'phone',
        'pets_present' => false,
        'bill_resident' => false,
    ], $overrides);
}

it('AC-MNT-16 raises a ticket the resident can see', function () {
    $this->actingAs($this->admin)
        ->post('/admin/maintenance', raisePayload())
        ->assertSessionHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->ticket_number)->not->toBeEmpty()
        ->and($ticket->lease_id)->toBe($this->lease->id)
        ->and($ticket->unit_id)->toBe($this->lease->unit_id)
        ->and($ticket->internal)->toBeFalse();

    // It reaches their portal like one they raised themselves.
    $this->actingAs($this->resident)
        ->get('/portal/maintenance')
        ->assertInertia(fn ($page) => $page->has('tickets', 1));
});

it('AC-MNT-16 says the office raised it, not the resident', function () {
    $this->actingAs($this->admin)->post('/admin/maintenance', raisePayload());

    // A permanent timeline somebody may later rely on should not claim the
    // resident reported something they did not.
    expect(Ticket::sole()->events()->first()->note)->toBe('Raised by the office.');
});

it('AC-MNT-17 assigns a contractor on the same form', function () {
    $this->actingAs($this->admin)
        ->post('/admin/maintenance', raisePayload(['vendor_id' => $this->vendor->id]))
        ->assertSessionHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->vendor_id)->toBe($this->vendor->id)
        ->and($ticket->status)->toBe('assigned')
        ->and($ticket->events()->pluck('note')->implode(' '))->toContain('Mikes Plumbing');
});

it('AC-MNT-17 bills the resident on the same form, once', function () {
    $this->actingAs($this->admin)
        ->post('/admin/maintenance', raisePayload([
            'bill_resident' => true,
            'billed_amount' => '180.00',
            'billed_reason' => 'Tap damaged by the resident',
        ]))
        ->assertSessionHasNoErrors();

    $ticket = Ticket::sole();
    $charge = LedgerEntry::where('type', 'charge')->sole();

    expect($ticket->billed_ledger_entry_id)->toBe($charge->id)
        // The ticket number is in the wording, so "what is this $180" is
        // answered by the line itself a year later.
        ->and($charge->description)->toContain($ticket->ticket_number)
        // Never the housing authority. A repair is not the agency's to pay for.
        ->and($charge->payer)->toBe('tenant')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('180.00');
});

it('AC-MNT-17 refuses a billed ticket with no amount', function () {
    $this->actingAs($this->admin)
        ->post('/admin/maintenance', raisePayload(['bill_resident' => true, 'billed_reason' => 'Damage']))
        ->assertSessionHasErrors('billed_amount');

    // A ticked box with no amount is somebody who meant to charge and did not.
    expect(Ticket::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0);
});

/*
 |--------------------------------------------------------------------------
 | Internal tickets
 |--------------------------------------------------------------------------
 */

it('AC-MNT-18 keeps an internal ticket out of the resident portal', function () {
    $this->actingAs($this->admin)
        ->post('/admin/maintenance', raisePayload(['internal' => true]))
        ->assertSessionHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->internal)->toBeTrue();

    $this->actingAs($this->resident)
        ->get('/portal/maintenance')
        ->assertInertia(fn ($page) => $page->has('tickets', 0));

    // 404, not 403 — a 403 confirms it exists, which is the whole thing being
    // kept back (I-9).
    $this->actingAs($this->resident)->get("/portal/maintenance/{$ticket->id}")->assertNotFound();

    // And they cannot close what they were never shown.
    $this->actingAs($this->resident)->post("/portal/maintenance/{$ticket->id}/confirm")->assertNotFound();
});

it('AC-MNT-18 sends the resident no email about an internal ticket, even when a contractor is assigned', function () {
    // The leak that matters. `assignVendor()` emails "X has been assigned to
    // your request" — on an internal ticket that announces by email the very
    // work the portal filter is hiding.
    $this->actingAs($this->admin)
        ->post('/admin/maintenance', raisePayload([
            'internal' => true,
            'vendor_id' => $this->vendor->id,
        ]))
        ->assertSessionHasNoErrors();

    expect(Ticket::sole()->status)->toBe('assigned')
        ->and(DB::table('notification_logs')->where('recipient', $this->tenant->email)->count())->toBe(0);
});

it('AC-MNT-18 still emails the resident about an ordinary admin-raised ticket', function () {
    // The contrast that makes the rule above a rule and not a regression.
    $this->actingAs($this->admin)
        ->post('/admin/maintenance', raisePayload(['vendor_id' => $this->vendor->id]));

    expect(DB::table('notification_logs')->where('recipient', $this->tenant->email)->count())
        ->toBeGreaterThan(0);
});

/*
 |--------------------------------------------------------------------------
 | Guards
 |--------------------------------------------------------------------------
 */

it('refuses a ticket against an ended tenancy', function () {
    $this->lease->forceFill(['status' => 'ended'])->save();

    $this->actingAs($this->admin)
        ->post('/admin/maintenance', raisePayload())
        ->assertSessionHasErrors('lease_id');

    expect(Ticket::count())->toBe(0);
});

it('keeps non-admins out of raising tickets', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/admin/maintenance/new')->assertForbidden();
    $this->actingAs($user)->post('/admin/maintenance', raisePayload())->assertForbidden();

    expect(Ticket::count())->toBe(0);
})->with(['tenant', 'owner']);
