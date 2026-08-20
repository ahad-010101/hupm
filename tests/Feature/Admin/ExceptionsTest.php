<?php

use App\Domain\Delinquency\DelinquencyService;
use App\Domain\Payments\ReconciliationService;
use App\Domain\Reporting\ExceptionFeed;
use App\Models\Lease;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Payment;
use App\Models\PaymentProfile;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| The exceptions panel  [WP-26, FR-ADM-01, AC-ADM-02, UI §3.7]
|--------------------------------------------------------------------------
|
| AC-ADM-02 is the one with teeth: a returned payment stays on the panel until
| somebody acknowledges it. Nothing in DB §A3 could record that, which is
| deviation D-25 — so the acknowledgement, its idempotency and the refusal to
| acknowledge the kinds that clear themselves are all asserted here.
|
| The other half of the package is what does NOT appear: an acknowledged item,
| a triaged emergency, a Management Review entered last month.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->feed = app(ExceptionFeed::class);
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->property = Property::factory()->create(['name' => 'Peachtree Court']);
});

function exceptionLease(array $overrides = []): Lease
{
    $tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);

    $lease = new Lease;
    $lease->forceFill(array_merge([
        'unit_id' => Unit::factory()->create(['property_id' => test()->property->id])->id,
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '600.00',
        'ha_portion' => '600.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => Lease::STATUS_ACTIVE,
    ], $overrides))->save();

    return $lease;
}

function exceptionTicket(Lease $lease, array $overrides = []): Ticket
{
    static $sequence = 0;
    $sequence++;

    $ticket = new Ticket;
    $ticket->forceFill(array_merge([
        'ticket_number' => sprintf('E-2026-%04d', $sequence),
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'unit_id' => $lease->unit_id,
        'category' => 'plumbing',
        'description' => 'Water coming through the ceiling.',
        'date_began' => '2026-08-01',
        'permission_to_enter' => true,
        'preferred_contact' => 'phone',
        'pets_present' => false,
        'is_emergency' => true,
        'urgency' => 'emergency',
        'status' => Ticket::STATUS_SUBMITTED,
    ], $overrides))->save();

    return $ticket;
}

function exceptionProfile(Lease $lease, string $status, string $suffix): PaymentProfile
{
    $profile = new PaymentProfile;
    $profile->forceFill([
        'tenant_id' => $lease->tenant_id,
        'gateway_customer_profile_id' => 'cust_'.$suffix,
        'gateway_payment_profile_id' => 'pay_'.$suffix,
        'descriptor' => 'Checking '.$suffix,
        'status' => $status,
    ])->save();

    return $profile;
}

/** @return list<string> */
function kindsOf(array $items): array
{
    return array_values(array_unique(array_column($items, 'kind')));
}

/*
 |--------------------------------------------------------------------------
 | AC-ADM-02 — until acknowledged
 |--------------------------------------------------------------------------
 */

it('AC-ADM-02 keeps a returned payment on the panel until it is acknowledged', function () {
    $lease = exceptionLease();

    $payment = Payment::factory()->create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'amount' => '600.00',
        'status' => Payment::STATUS_RETURNED,
        'return_code' => 'R01',
        'return_description' => 'Insufficient funds',
        'returned_at' => now(),
    ]);

    $this->actingAs($this->admin)->get('/admin/exceptions')
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Exceptions')
            ->has('exceptions', 1)
            ->where('exceptions.0.kind', 'returned_payment')
            ->where('exceptions.0.subject_id', $payment->id)
            // Links to the account, not to a list of returns: the balance has
            // gone back up and somebody has to ring them about it.
            ->where('exceptions.0.href', "/admin/ledger/{$lease->tenant_id}")
            ->where('exceptions.0.acknowledgeable', true));

    $this->actingAs($this->admin)
        ->post('/admin/exceptions/acknowledge', ['kind' => 'returned_payment', 'subject_id' => $payment->id])
        ->assertRedirect();

    $this->actingAs($this->admin)->get('/admin/exceptions')
        ->assertInertia(fn ($page) => $page->has('exceptions', 0));
});

it('changes nothing about the payment when it is acknowledged', function () {
    $lease = exceptionLease();
    $payment = Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'status' => Payment::STATUS_RETURNED, 'returned_at' => now(),
    ]);

    $before = $payment->fresh()->getAttributes();

    $this->actingAs($this->admin)
        ->post('/admin/exceptions/acknowledge', ['kind' => 'returned_payment', 'subject_id' => $payment->id]);

    // D-25: acknowledgement is a fact about a person's attention, not about the
    // money. The exceptions panel has no write path into a payment row.
    expect($payment->fresh()->getAttributes())->toBe($before);
});

it('treats a second acknowledgement as the same fact, not a second one', function () {
    $lease = exceptionLease();
    $payment = Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'status' => Payment::STATUS_RETURNED, 'returned_at' => now(),
    ]);

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($this->admin)
            ->post('/admin/exceptions/acknowledge', ['kind' => 'returned_payment', 'subject_id' => $payment->id])
            ->assertRedirect();
    }

    expect(DB::table('exception_acknowledgements')->count())->toBe(1);
});

it('records who acknowledged it and when, and audits it', function () {
    $lease = exceptionLease();
    $payment = Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'status' => Payment::STATUS_RETURNED, 'returned_at' => now(),
    ]);

    $this->actingAs($this->admin)->post('/admin/exceptions/acknowledge', [
        'kind' => 'returned_payment',
        'subject_id' => $payment->id,
        'note' => 'Spoke to the resident; paying by cheque on Friday.',
    ]);

    $row = DB::table('exception_acknowledgements')->first();

    expect($row->acknowledged_by_user_id)->toBe($this->admin->id)
        ->and($row->acknowledged_at)->not->toBeNull()
        ->and($row->note)->toContain('cheque on Friday');

    expect(DB::table('audit_logs')->where('action', 'exception.acknowledged')->exists())->toBeTrue();
});

it('refuses to acknowledge a kind that clears when the work is done', function () {
    $lease = exceptionLease();
    $ticket = exceptionTicket($lease);

    // Dismissing an untriaged emergency would hide the work rather than record
    // that somebody had done it.
    $this->actingAs($this->admin)
        ->post('/admin/exceptions/acknowledge', ['kind' => 'emergency_ticket', 'subject_id' => $ticket->id])
        ->assertSessionHasErrors('kind');

    expect(DB::table('exception_acknowledgements')->count())->toBe(0);

    expect(fn () => $this->feed->acknowledge('emergency_ticket', $ticket->id, $this->admin))
        ->toThrow(InvalidArgumentException::class);
});

/*
 |--------------------------------------------------------------------------
 | The six kinds
 |--------------------------------------------------------------------------
 */

it('raises an untriaged emergency and drops it once somebody has it', function () {
    $lease = exceptionLease();
    $ticket = exceptionTicket($lease);

    expect(kindsOf($this->feed->items()))->toContain('emergency_ticket');

    $ticket->forceFill(['status' => Ticket::STATUS_TRIAGED])->save();

    // A triaged emergency has an owner and lives in the ticket queue on the
    // same screen; it is no longer something nobody has looked at.
    expect(kindsOf($this->feed->items()))->not->toContain('emergency_ticket');
});

it('raises a failed autopay, which is a different fact from an unpaid resident', function () {
    $lease = exceptionLease();
    $profile = exceptionProfile($lease, PaymentProfile::STATUS_ACTIVE, '1234');

    Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'payment_profile_id' => $profile->id,
        'status' => Payment::STATUS_FAILED,
        'return_description' => 'Account closed',
    ]);

    // A manual payment that failed is not autopay, and does not belong here.
    Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'status' => Payment::STATUS_FAILED,
    ]);

    $items = array_values(array_filter($this->feed->items(), fn ($i) => $i['kind'] === 'failed_autopay'));

    expect($items)->toHaveCount(1)
        ->and($items[0]['detail'])->toContain('Account closed')
        ->and($items[0]['acknowledgeable'])->toBeTrue();
});

it('raises a NOC flag and takes it away when the profile is fixed', function () {
    $lease = exceptionLease();
    $profile = exceptionProfile($lease, PaymentProfile::STATUS_NEEDS_UPDATE, '5678');

    $items = array_values(array_filter($this->feed->items(), fn ($i) => $i['kind'] === 'noc_flag'));

    expect($items)->toHaveCount(1)
        ->and($items[0]['href'])->toBe("/admin/tenants/{$lease->tenant_id}")
        // Cleared by updating the details, never by waving it away.
        ->and($items[0]['acknowledgeable'])->toBeFalse();

    $profile->forceFill(['status' => PaymentProfile::STATUS_ACTIVE])->save();

    expect(kindsOf($this->feed->items()))->not->toContain('noc_flag');
});

it('raises a payment still pending past the reconciliation window', function () {
    $lease = exceptionLease();

    Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'gateway' => 'authorize_net',
        'gateway_transaction_id' => '60000001',
        'status' => Payment::STATUS_PENDING,
        'submitted_at' => now()->subDays(ReconciliationService::WINDOW_DAYS + 1),
    ]);

    // Inside the window it is simply an ACH in flight, not an exception.
    Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'gateway' => 'authorize_net',
        'gateway_transaction_id' => '60000002',
        'status' => Payment::STATUS_PENDING,
        'submitted_at' => now()->subDay(),
    ]);

    $items = array_values(array_filter($this->feed->items(), fn ($i) => $i['kind'] === 'unmatched_payment'));

    expect($items)->toHaveCount(1);
});

it('raises an account that entered Management Review this week, but not last month', function () {
    $fresh = exceptionLease();
    $fresh->forceFill([
        'delinquency_state' => DelinquencyService::STATE_REVIEW,
        'delinquency_since' => now()->subDays(2),
    ])->save();

    $stale = exceptionLease();
    $stale->forceFill([
        'delinquency_state' => DelinquencyService::STATE_REVIEW,
        'delinquency_since' => now()->subDays(40),
    ])->save();

    $items = array_values(array_filter($this->feed->items(), fn ($i) => $i['kind'] === 'management_review'));

    // "Newly" is the word UI §3.7 uses. Every account in review already has its
    // own queue; what belongs here is the transition nobody watched happen.
    expect($items)->toHaveCount(1)
        ->and($items[0]['subject_id'])->toBe($fresh->id);
});

/*
 |--------------------------------------------------------------------------
 | Ordering, counting and the empty state
 |--------------------------------------------------------------------------
 */

it('puts the emergency above the money, whatever order they arrived in', function () {
    $lease = exceptionLease();

    Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'status' => Payment::STATUS_RETURNED, 'returned_at' => now()->subDays(5),
    ]);
    exceptionTicket($lease);

    expect($this->feed->items()[0]['kind'])->toBe('emergency_ticket');
});

it('counts the badge from the same list the panel renders', function () {
    $lease = exceptionLease();
    exceptionTicket($lease);
    Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'status' => Payment::STATUS_RETURNED, 'returned_at' => now(),
    ]);

    $summary = $this->feed->summary();

    // A badge saying 3 above a panel showing 2 is worse than no badge.
    expect($summary['total'])->toBe(count($this->feed->items()))
        ->and($summary['by_kind'])->toBe(['emergency_ticket' => 1, 'returned_payment' => 1]);
});

it('shares the exception count with every admin screen, and with no tenant screen', function () {
    $lease = exceptionLease();
    exceptionTicket($lease);

    // Not the dashboard: any admin page, because the badge is in the shell.
    $this->actingAs($this->admin)->get('/admin/payments')
        ->assertInertia(fn ($page) => $page->where('exceptionSummary.total', 1));

    $resident = User::factory()->create(['role' => 'tenant', 'tenant_id' => $lease->tenant_id]);

    $this->actingAs($resident)->get('/portal')
        ->assertInertia(fn ($page) => $page->where('exceptionSummary', null));
});

it('says nothing needs attention rather than showing an empty box', function () {
    exceptionLease();

    $this->actingAs($this->admin)->get('/admin/exceptions')
        ->assertInertia(fn ($page) => $page
            ->has('exceptions', 0)
            ->where('summary.total', 0));
});

it('keeps a tenant out of the exceptions screen entirely', function () {
    $lease = exceptionLease();
    $resident = User::factory()->create(['role' => 'tenant', 'tenant_id' => $lease->tenant_id]);

    $this->actingAs($resident)->get('/admin/exceptions')->assertForbidden();
    $this->actingAs($resident)
        ->post('/admin/exceptions/acknowledge', ['kind' => 'returned_payment', 'subject_id' => 1])
        ->assertForbidden();
});

/*
 |--------------------------------------------------------------------------
 | Global search
 |--------------------------------------------------------------------------
 */

it('finds a resident, a unit and a ticket from one box', function () {
    $lease = exceptionLease();
    $ticket = exceptionTicket($lease);

    $this->actingAs($this->admin)->get('/admin/search?q=Pouros')
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Search')
            ->has('results.tenants', 1)
            ->where('results.tenants.0.name', 'Uriel Pouros'));

    $this->actingAs($this->admin)->get('/admin/search?q=Peachtree')
        ->assertInertia(fn ($page) => $page->has('results.units', 1));

    $this->actingAs($this->admin)->get('/admin/search?q='.$ticket->ticket_number)
        ->assertInertia(fn ($page) => $page
            ->has('results.tickets', 1)
            ->where('results.tickets.0.href', "/admin/maintenance/{$ticket->id}"));
});

it('refuses to match everything on a one-character search', function () {
    exceptionLease();

    $this->actingAs($this->admin)->get('/admin/search?q=P')
        ->assertInertia(fn ($page) => $page->where('results.total', 0));
});

it('treats a LIKE wildcard as text, not as a search for everyone', function () {
    exceptionLease();

    $this->actingAs($this->admin)->get('/admin/search?q=%25')
        ->assertInertia(fn ($page) => $page->where('results.total', 0));
});
