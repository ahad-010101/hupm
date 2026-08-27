<?php

use App\Domain\Maintenance\MaintenanceService;
use App\Domain\Maintenance\TicketStateMachine;
use App\Domain\Notifications\NotificationTemplate;
use App\Exceptions\ImmutableRecordException;
use App\Models\Lease;
use App\Models\MaintenanceAttachment;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Maintenance requests  [WP-19, FR-MNT-01…03, BR-23/24]
|--------------------------------------------------------------------------
|
| A repair request is the one part of this system a resident uses when
| something is actively going wrong. The rules that matter are about not
| making that worse: the 911 warning comes first, a rejected photo never
| costs them the form, and a ticket cannot be quietly marked done.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->maintenance = app(MaintenanceService::class);
    $this->states = app(TicketStateMachine::class);

    $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Dana Reeves']);
    $this->tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);
    $this->user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

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

/** @return array<string, mixed> */
function ticketPayload(array $overrides = []): array
{
    return array_merge([
        'emergency_acknowledged' => true,
        'category' => 'plumbing',
        'description' => 'The kitchen tap has been dripping steadily since Tuesday.',
        'permission_to_enter' => '1',
        'preferred_contact' => 'email',
        'pets_present' => false,
        'is_emergency' => false,
    ], $overrides);
}

function submitTicket(array $overrides = [], array $files = []): Ticket
{
    return test()->maintenance->submit(
        test()->lease,
        array_merge(ticketPayload(), $overrides),
        $files,
        test()->user,
    );
}

/*
 |--------------------------------------------------------------------------
 | Submitting
 |--------------------------------------------------------------------------
 */

it('AC-MNT-01 refuses to submit until the emergency warning is acknowledged', function () {
    $this->actingAs($this->user)
        ->post('/portal/maintenance', ticketPayload(['emergency_acknowledged' => false]))
        ->assertSessionHasErrors('emergency_acknowledged');

    // BR-23. Enforced on the server, because a rule that lives only in
    // JavaScript is not a rule — and the person it protects may be standing in
    // a flooded kitchen.
    expect(Ticket::count())->toBe(0);
});

it('AC-MNT-03 issues a sequential ticket number and tells the office', function () {
    $this->actingAs($this->user)->post('/portal/maintenance', ticketPayload());

    $ticket = Ticket::sole();

    expect($ticket->ticket_number)->toBe('MR-1001')
        ->and($ticket->status)->toBe('submitted')
        ->and(DB::table('notification_logs')
            ->where('template', NotificationTemplate::MaintenanceStatusChange->value)
            ->where('user_id', $this->admin->id)
            ->exists())->toBeTrue();
});

it('numbers tickets one after another, never reusing one', function () {
    $numbers = collect(range(1, 3))->map(fn () => submitTicket()->ticket_number);

    // MAX(id)+1 would be the tempting shortcut and is exactly the race: two
    // readers see the same maximum. The counter row is locked instead.
    expect($numbers->all())->toBe(['MR-1001', 'MR-1002', 'MR-1003'])
        ->and($numbers->unique())->toHaveCount(3);
});

it('AC-MNT-04 marks an emergency urgent and puts it at the top of the queue', function () {
    submitTicket(['description' => 'Ordinary dripping tap, no rush at all.']);
    $urgent = submitTicket(['is_emergency' => true, 'description' => 'Water coming through the ceiling.']);

    $first = Ticket::query()->queueOrder()->first();

    expect($urgent->urgency)->toBe('emergency')
        // Urgent from the moment it is reported, not once somebody triages it.
        ->and($first->id)->toBe($urgent->id);
});

it('records the submission as the first event on the trail', function () {
    $ticket = submitTicket();

    $event = MaintenanceEvent::sole();

    expect($event->from_status)->toBeNull()
        ->and($event->to_status)->toBe('submitted')
        ->and($event->actor_user_id)->toBe($this->user->id);
});

it('needs a description of more than a couple of words', function () {
    $this->actingAs($this->user)
        ->post('/portal/maintenance', ticketPayload(['description' => 'broken']))
        ->assertSessionHasErrors('description');
});

/*
 |--------------------------------------------------------------------------
 | Photos
 |--------------------------------------------------------------------------
 */

it('stores a photo outside the web root under a name the tenant did not choose', function () {
    $ticket = submitTicket([], [UploadedFile::fake()->image('leak.jpg')]);

    $attachment = MaintenanceAttachment::sole();

    expect($attachment->original_filename)->toBe('leak.jpg')
        // The tenant's filename is display only; it never touches the path.
        ->and($attachment->stored_path)->not->toContain('leak.jpg')
        ->and($attachment->stored_path)->toStartWith("maintenance/{$ticket->id}/")
        ->and($attachment->visible_to_tenant)->toBeTrue();

    Storage::disk('local')->assertExists($attachment->stored_path);
});

it('AC-MNT-02 rejects a 25 MB upload and keeps everything else the tenant typed', function () {
    $response = $this->actingAs($this->user)->post('/portal/maintenance', ticketPayload([
        'description' => 'The boiler is making a loud knocking noise every morning.',
        'photos' => [UploadedFile::fake()->create('huge.mp4', 25 * 1024, 'video/mp4')],
    ]));

    $response->assertSessionHasErrors('photos.0');

    // A validation error, not an exception — which is what lets Inertia hand
    // the form back with the description still in it.
    expect(Ticket::count())->toBe(0)
        ->and(session()->getOldInput('description'))
        ->toBe('The boiler is making a loud knocking noise every morning.');
});

it('refuses a file that is not a photo or a video', function () {
    $this->actingAs($this->user)->post('/portal/maintenance', ticketPayload([
        'photos' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
    ]))->assertSessionHasErrors('photos.0');
});

it('accepts no more than five files', function () {
    $this->actingAs($this->user)->post('/portal/maintenance', ticketPayload([
        'photos' => collect(range(1, 6))->map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"))->all(),
    ]))->assertSessionHasErrors('photos');
});

/*
 |--------------------------------------------------------------------------
 | The state machine
 |--------------------------------------------------------------------------
 */

it('AC-MNT-08 refuses submitted straight to closed', function () {
    $ticket = submitTicket();

    // BR-24: a ticket closes when the resident confirms, or when an admin
    // force-closes with a reason. A path from "reported" to "done" would let a
    // repair be marked complete with no evidence anyone looked at it.
    $this->maintenance->transition($ticket, Ticket::STATUS_CLOSED, $this->admin, null, 'nope');
})->throws(InvalidArgumentException::class, 'cannot go from Submitted to Closed');

it('refuses to move a ticket that is already finished', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);
    $this->maintenance->transition($ticket, Ticket::STATUS_CANCELLED, $this->admin);

    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);
})->throws(InvalidArgumentException::class);

it('walks the whole ordinary lifecycle', function () {
    $ticket = submitTicket();

    foreach ([
        Ticket::STATUS_TRIAGED,
        Ticket::STATUS_ASSIGNED,
        Ticket::STATUS_SCHEDULED,
        Ticket::STATUS_IN_PROGRESS,
        Ticket::STATUS_AWAITING_CONFIRMATION,
    ] as $to) {
        $this->maintenance->transition($ticket->fresh(), $to, $this->admin);
    }

    expect($ticket->fresh()->status)->toBe(Ticket::STATUS_AWAITING_CONFIRMATION);
});

it('lets a resident say it is not fixed without opening a second ticket', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_IN_PROGRESS, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_AWAITING_CONFIRMATION, $this->admin);

    // One fault stays one ticket, so the history of every attempt survives.
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_IN_PROGRESS, $this->admin);

    expect($ticket->fresh()->status)->toBe(Ticket::STATUS_IN_PROGRESS);
});

it('AC-MNT-05 writes an immutable event with actor and timestamp on every move', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin, 'Looked at it.');

    $event = MaintenanceEvent::where('to_status', 'triaged')->sole();

    expect($event->from_status)->toBe('submitted')
        ->and($event->actor_user_id)->toBe($this->admin->id)
        ->and($event->created_at)->not->toBeNull()
        ->and($event->note)->toBe('Looked at it.');

    // Append-only, like the ledger and for the same reason: the history of a
    // repair is evidence. Two layers, and mass assignment is the outer one.
    expect(fn () => $event->update(['note' => 'Something else']))
        ->toThrow(MassAssignmentException::class);

    expect(fn () => $event->forceFill(['note' => 'Something else'])->save())
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => $event->delete())
        ->toThrow(ImmutableRecordException::class);
});

/*
 |--------------------------------------------------------------------------
 | Closing
 |--------------------------------------------------------------------------
 */

it('AC-MNT-06 closes when the resident confirms and tells both parties', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_IN_PROGRESS, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_AWAITING_CONFIRMATION, $this->admin);

    $this->actingAs($this->user)
        ->post("/portal/maintenance/{$ticket->id}/confirm")
        ->assertSessionHasNoErrors();

    $closed = $ticket->fresh();

    expect($closed->status)->toBe('closed')
        ->and($closed->closed_at)->not->toBeNull()
        // No reason: this is the ordinary way a ticket closes, and the only one
        // that needs no explanation.
        ->and($closed->close_reason)->toBeNull()
        ->and(DB::table('notification_logs')->where('tenant_id', $this->tenant->id)->count())
        ->toBeGreaterThan(1)
        ->and(DB::table('notification_logs')->where('user_id', $this->admin->id)->count())
        ->toBeGreaterThan(1);
});

it('AC-MNT-07 refuses a force-close with no reason', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_IN_PROGRESS, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_AWAITING_CONFIRMATION, $this->admin);

    $this->actingAs($this->admin)
        ->patch("/admin/maintenance/{$ticket->id}/status", ['to' => 'closed'])
        ->assertSessionHasErrors('close_reason');

    expect($ticket->fresh()->status)->toBe('awaiting_tenant_confirmation');
});

it('records the reason when an admin closes it anyway', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_IN_PROGRESS, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_AWAITING_CONFIRMATION, $this->admin);

    $this->actingAs($this->admin)->patch("/admin/maintenance/{$ticket->id}/status", [
        'to' => 'closed',
        'close_reason' => 'No answer after three calls over fourteen days.',
    ]);

    $closed = $ticket->fresh();

    expect($closed->status)->toBe('closed')
        ->and($closed->close_reason)->toBe('No answer after three calls over fourteen days.')
        ->and(MaintenanceEvent::where('to_status', 'closed')->sole()->note)
        ->toBe('No answer after three calls over fourteen days.');
});

/*
 |--------------------------------------------------------------------------
 | Contractors, and what a tenant may not see
 |--------------------------------------------------------------------------
 */

it('AC-MNT-09 shows the tenant who is coming and never what it costs', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);

    $vendor = Vendor::factory()->create(['name' => 'Marsh Plumbing', 'trade' => 'Plumbing']);
    $this->maintenance->assignVendor($ticket->fresh(), $vendor, $this->admin);

    // An invoice on the ticket. Someone has to knock on the door; nobody has to
    // tell the resident what the landlord paid.
    $this->maintenance->attach(
        $ticket->fresh(),
        UploadedFile::fake()->image('invoice.jpg'),
        MaintenanceAttachment::KIND_INVOICE,
        $this->admin,
    );

    $props = [];
    $this->actingAs($this->user)
        ->get("/portal/maintenance/{$ticket->id}")
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    $encoded = json_encode($props);

    expect($props['ticket']['contractor'])->toBe('Marsh Plumbing')
        ->and($props['photos'])->toHaveCount(0)
        ->and($encoded)->not->toContain('invoice')
        ->not->toContain('cost');
});

it('keeps internal notes off the tenant timeline', function () {
    $ticket = submitTicket();
    // `triaged` is not a tenant-visible transition, so its event is internal.
    $this->maintenance->transition($ticket, Ticket::STATUS_ASSIGNED, $this->admin, 'internal');
})->throws(InvalidArgumentException::class);

it('keeps an admin note off the tenant timeline while showing the assignment', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);

    $vendor = Vendor::factory()->create(['name' => 'Marsh Plumbing', 'trade' => 'Plumbing']);
    $this->maintenance->assignVendor($ticket->fresh(), $vendor, $this->admin, 'Cheapest of three quotes; watch the invoice.');

    $props = [];
    $this->actingAs($this->user)
        ->get("/portal/maintenance/{$ticket->id}")
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    $encoded = json_encode($props);

    // We email the resident about the assignment, so it belongs on their
    // timeline. What we say to ourselves about the quote does not — and
    // concatenating the two was one careless sentence from showing them.
    expect($encoded)->toContain('Marsh Plumbing')
        ->not->toContain('Cheapest of three quotes');
});

it('shows the resident only the transitions that concern them', function () {
    $ticket = submitTicket();
    $this->maintenance->transition($ticket, Ticket::STATUS_TRIAGED, $this->admin);
    $this->maintenance->transition($ticket->fresh(), Ticket::STATUS_ASSIGNED, $this->admin, 'Chasing three quotes.');

    $props = [];
    $this->actingAs($this->user)
        ->get("/portal/maintenance/{$ticket->id}")
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    // A bare `assigned` transition with no vendor is a queue movement the
    // resident cannot see. The quote-chasing note is ours.
    expect(collect($props['events'])->pluck('status'))->not->toContain('Contractor assigned')
        ->and(json_encode($props))->not->toContain('Chasing three quotes');
});

/*
 |--------------------------------------------------------------------------
 | Access
 |--------------------------------------------------------------------------
 */

it('I-9 returns 404 on another resident’s ticket, never 403', function () {
    $ticket = submitTicket();

    $other = Tenant::factory()->create();
    $otherUser = User::factory()->create(['role' => 'tenant', 'tenant_id' => $other->id]);

    // A 403 would confirm the ticket exists.
    $this->actingAs($otherUser)->get("/portal/maintenance/{$ticket->id}")->assertNotFound();
    $this->actingAs($otherUser)->post("/portal/maintenance/{$ticket->id}/confirm")->assertNotFound();
});

it('keeps a tenant out of the admin queue', function () {
    $this->actingAs($this->user)->get('/admin/maintenance')->assertForbidden();
});

it('offers the admin only the moves that are legal from here', function () {
    $ticket = submitTicket();

    $props = [];
    $this->actingAs($this->admin)
        ->get("/admin/maintenance/{$ticket->id}")
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    // AC-MNT-08 on screen, not just in the service: an action that would be
    // refused should not be offered (UI §9).
    expect(array_keys($props['nextStates']))->toBe(['triaged', 'cancelled']);
});

it('sorts urgent tickets to the top of the admin queue', function () {
    submitTicket(['description' => 'Dripping tap in the bathroom, not urgent.']);
    $urgent = submitTicket(['is_emergency' => true, 'description' => 'Smell of burning from a socket.']);

    $props = [];
    $this->actingAs($this->admin)->get('/admin/maintenance')->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    expect($props['tickets']['data'][0]['ticket_number'])->toBe($urgent->ticket_number)
        ->and($props['emergencyCount'])->toBe(1);
});
