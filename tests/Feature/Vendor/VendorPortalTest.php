<?php

use App\Domain\Maintenance\MaintenanceService;
use App\Models\Lease;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Contractor portal  [WP-44, reverses NG-6]
|--------------------------------------------------------------------------
|
| A contractor is a third party holding a resident's telephone number, their
| access arrangements and the fact that they are home after four. That is the
| sharpest disclosure in the system and it is justified only by the job — so
| what these tests really police is the edge of it.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->maintenance = app(MaintenanceService::class);
    $this->admin = User::factory()->admin()->create();

    $this->mine = Vendor::factory()->create(['name' => "Mike's Plumbing", 'email' => 'mike@test.test']);
    $this->theirs = Vendor::factory()->create(['name' => 'Other Trades', 'email' => 'other@test.test']);

    $this->vendorUser = User::factory()->create([
        'role' => User::ROLE_VENDOR,
        'vendor_id' => $this->mine->id,
    ]);

    $this->tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);
    $property = Property::factory()->create(['name' => 'Peachtree House']);

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create(['property_id' => $property->id, 'unit_number' => '2B'])->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();
});

function vendorTicket(?Vendor $vendor = null, string $status = 'assigned'): Ticket
{
    $ticket = new Ticket;
    $ticket->forceFill([
        'ticket_number' => 'MR-'.fake()->unique()->numberBetween(1000, 9999),
        'lease_id' => test()->lease->id,
        'tenant_id' => test()->tenant->id,
        'unit_id' => test()->lease->unit_id,
        'category' => 'plumbing',
        'description' => 'Leaking kitchen tap, dripping steadily since Tuesday.',
        'permission_to_enter' => true,
        'preferred_contact' => 'phone',
        'contact_phone' => '(404) 555-0101',
        'pets_present' => true,
        'best_access_time' => 'After 4pm',
        'is_emergency' => false,
        'status' => $status,
        'vendor_id' => $vendor?->id,
    ])->save();

    return $ticket;
}

it('AC-VEN-03 shows a contractor their own jobs and nobody else\'s', function () {
    $mine = vendorTicket($this->mine);
    vendorTicket($this->theirs);
    vendorTicket(null); // unassigned

    $this->actingAs($this->vendorUser)
        ->get('/work')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Vendor/Tickets')
            ->has('open', 1)
            ->where('open.0.ticket_number', $mine->ticket_number));
});

it('AC-VEN-03 returns 404, not 403, for another contractor\'s job', function () {
    $theirs = vendorTicket($this->theirs);

    // 403 would confirm the ticket exists, which tells a contractor something
    // about a property they have no business knowing (I-9).
    $this->actingAs($this->vendorUser)
        ->get("/work/{$theirs->id}")
        ->assertNotFound();
});

it('AC-VEN-04 gives a contractor what the job needs and nothing more', function () {
    $ticket = vendorTicket($this->mine);

    $this->actingAs($this->vendorUser)
        ->get("/work/{$ticket->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Vendor/TicketShow')
            // The access details, without which the visit fails and a resident
            // takes another afternoon off.
            ->where('ticket.contact_phone', '(404) 555-0101')
            ->where('ticket.best_access_time', 'After 4pm')
            ->where('ticket.pets_present', true)
            // And nothing financial reaches the page at all.
            ->missing('ticket.balance')
            ->missing('ticket.rent')
            ->missing('ticket.tenant_portion'));
});

it('AC-VEN-04 lets a contractor move a job along', function () {
    $ticket = vendorTicket($this->mine, 'assigned');

    $this->actingAs($this->vendorUser)
        ->patch("/work/{$ticket->id}", ['to' => 'in_progress', 'note' => 'On site now'])
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->status)->toBe('in_progress');
});

it('AC-VEN-04 holds a contractor to the same state machine as an admin', function () {
    $ticket = vendorTicket($this->mine, 'assigned');

    // assigned -> closed is not a legal move for anybody. A contractor does not
    // get a second set of rules.
    $this->actingAs($this->vendorUser)
        ->patch("/work/{$ticket->id}", ['to' => 'closed'])
        ->assertSessionHasErrors('to');

    expect($ticket->fresh()->status)->toBe('assigned');
});

it('AC-VEN-04 refuses to let a contractor touch another contractor\'s job', function () {
    $theirs = vendorTicket($this->theirs, 'assigned');

    $this->actingAs($this->vendorUser)
        ->patch("/work/{$theirs->id}", ['to' => 'in_progress'])
        ->assertNotFound();

    expect($theirs->fresh()->status)->toBe('assigned');
});

it('AC-VEN-05 keeps a contractor out of every other part of the system', function (string $path) {
    $this->actingAs($this->vendorUser)->get($path)->assertForbidden();
})->with(['/admin', '/admin/ledger', '/admin/payments', '/portal', '/portal/pay']);

it('AC-VEN-05 keeps everybody else out of the contractor portal', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get('/work')->assertForbidden();
})->with(['admin', 'tenant']);
