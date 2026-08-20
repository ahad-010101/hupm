<?php

use App\Domain\Ledger\LedgerService;
use App\Domain\Reporting\DashboardFilters;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\MaintenanceRequest as Ticket;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Admin dashboard  [WP-26, FR-ADM-01, UI §3.7, API-ADM-01]
|--------------------------------------------------------------------------
|
| Two things are load-bearing here and both are easy to break silently:
|
|   1. The KPI figures come from the ledger at read time (I-1). A dashboard
|      that disagreed with the ledger screen it links to would be worse than
|      no dashboard, so the arithmetic is asserted rather than eyeballed.
|   2. Filter state lives in the URL (AC-ADM-01). Tested by sending a URL and
|      reading what comes back, which is the only way to prove a colleague can
|      be sent one.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->ledger = app(LedgerService::class);
    $this->calendar = app(BusinessCalendar::class);
    $this->period = $this->calendar->currentPeriod();

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->property = Property::factory()->create(['name' => 'Peachtree Court']);
    $this->authority = HousingAuthority::factory()->create(['name' => 'Atlanta Housing']);
});

/** A resident with an active lease and, optionally, rent owing. */
function dashboardLease(array $overrides = [], ?string $owes = null): Lease
{
    $tenant = Tenant::factory()->create();

    $lease = new Lease;
    $lease->forceFill(array_merge([
        'unit_id' => Unit::factory()->create(['property_id' => test()->property->id])->id,
        'tenant_id' => $tenant->id,
        'housing_authority_id' => test()->authority->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '600.00',
        'ha_portion' => '600.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => Lease::STATUS_ACTIVE,
    ], $overrides))->save();

    if ($owes !== null) {
        test()->ledger->postCharge(
            $lease, 'rent', 'tenant', Money::fromString($owes),
            'Rent', 'dash:'.$lease->id.':'.test()->period,
            test()->calendar->today()->startOfMonth(), test()->period,
        );
    }

    return $lease;
}

/*
 |--------------------------------------------------------------------------
 | Access
 |--------------------------------------------------------------------------
 */

it('is admin only', function () {
    $tenant = Tenant::factory()->create();
    $resident = User::factory()->create(['role' => 'tenant', 'tenant_id' => $tenant->id]);

    // Wrong role is 403, not 404: the dashboard's existence is not a secret,
    // only a resident's records are (I-9).
    $this->actingAs($resident)->get('/admin')->assertForbidden();
});

it('sends an anonymous visitor to log in', function () {
    $this->get('/admin')->assertRedirect('/login');
});

/*
 |--------------------------------------------------------------------------
 | UI §7 — the empty portfolio
 |--------------------------------------------------------------------------
 */

it('shows a setup checklist rather than zeroed widgets on an empty portfolio', function () {
    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('setup.empty', true)
            ->has('setup.steps', 5)
            // No zeroes to misread as facts about a portfolio that does not
            // exist yet.
            ->where('kpis', null)
            ->where('panels', null));
});

it('leaves the checklist behind once there is an active lease', function () {
    dashboardLease();

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->where('setup.empty', false)
            ->has('kpis')
            ->has('panels'));
});

it('ticks off the steps that are already done', function () {
    Tenant::factory()->create();

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(function ($page) {
            $steps = collect($page->toArray()['props']['setup']['steps'])->keyBy('label');

            expect($steps['Add your properties']['done'])->toBeTrue()
                ->and($steps['Add the residents']['done'])->toBeTrue()
                ->and($steps['Create the leases']['done'])->toBeFalse();
        });
});

/*
 |--------------------------------------------------------------------------
 | The KPI row
 |--------------------------------------------------------------------------
 */

it('counts occupancy from active leases, not from unit status', function () {
    dashboardLease();
    Unit::factory()->count(3)->create(['property_id' => $this->property->id]);

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.occupied', 1)
            ->where('kpis.vacant', 3)
            ->where('kpis.units_total', 4)
            ->where('kpis.occupancy_percent', 25));
});

it('counts money collected this month by posting date, and splits it by payer', function () {
    $lease = dashboardLease([], '600.00');

    // Cleared: this is money in the bank.
    $this->ledger->postPayment(
        $lease, 'tenant', Money::fromString('400.00'), 'Part payment',
        null, 'cleared', $this->calendar->today(),
    );
    $this->ledger->postPayment(
        $lease, 'housing_authority', Money::fromString('600.00'), 'HAP',
        null, 'cleared', $this->calendar->today(),
    );

    // Pending: submitted, not collected. A payment reduces a balance only on
    // settlement (I-6), and it must not inflate this figure either.
    $this->ledger->postPayment(
        $lease, 'tenant', Money::fromString('999.00'), 'Still processing',
        null, 'pending', $this->calendar->today(),
    );

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.collected_total', '1000.00')
            ->where('kpis.collected_tenant', '400.00')
            ->where('kpis.collected_ha', '600.00'));
});

it('leaves last month out of what was collected this month', function () {
    $lease = dashboardLease([], '600.00');

    $this->ledger->postPayment(
        $lease, 'tenant', Money::fromString('500.00'), 'Last month',
        null, 'cleared', $this->calendar->today()->subMonthNoOverflow()->startOfMonth(),
    );

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page->where('kpis.collected_total', '0.00'));
});

it('totals what is outstanding across the portfolio, keeping the payers apart', function () {
    dashboardLease([], '600.00');
    dashboardLease([], '250.00');

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->where('kpis.outstanding_tenant', '850.00')
            // Charged to nobody yet, so nothing is owed by the authority.
            ->where('kpis.outstanding_ha', '0.00'));
});

it('counts an account as past due only once its own grace period has run out', function () {
    // Today is the 20th of the month in company time, so a 5-day grace has
    // expired and a 40-day one has not.
    CarbonImmutable::setTestNow(
        $this->calendar->today()->startOfMonth()->addDays(19)->setTime(12, 0)->utc(),
    );

    dashboardLease(['grace_period_days' => 5], '600.00');
    dashboardLease(['grace_period_days' => 40], '600.00');
    dashboardLease(['grace_period_days' => 5]);  // owes nothing

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page->where('kpis.past_due_count', 1));

    CarbonImmutable::setTestNow();
});

/*
 |--------------------------------------------------------------------------
 | AC-ADM-01 — filters in the URL
 |--------------------------------------------------------------------------
 */

it('AC-ADM-01 reflects the applied filters in props so the URL round-trips', function () {
    $lease = dashboardLease();

    $this->actingAs($this->admin)
        ->get("/admin?property={$this->property->id}&tenant={$lease->tenant_id}&payment_status=returned&maintenance_status=open&expiry=30&housing_authority={$this->authority->id}")
        ->assertInertia(fn ($page) => $page
            ->where('filters.property', $this->property->id)
            ->where('filters.tenant', $lease->tenant_id)
            ->where('filters.payment_status', 'returned')
            ->where('filters.maintenance_status', 'open')
            ->where('filters.expiry', 30)
            ->where('filters.housing_authority', $this->authority->id));
});

it('AC-ADM-01 leaves the default expiry window out of the filter state', function () {
    dashboardLease();

    // An untouched dashboard has a clean URL, so "is anything filtered?" stays
    // a question the screen can answer.
    $this->actingAs($this->admin)->get('/admin?expiry='.DashboardFilters::DEFAULT_EXPIRY_WINDOW)
        ->assertInertia(fn ($page) => $page->where('filters', []));
});

it('drops a filter value it does not recognise instead of erroring on a stale link', function () {
    dashboardLease();

    $this->actingAs($this->admin)
        ->get('/admin?property=99999&payment_status=exploded&expiry=7&tenant=not-a-number')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // A property id that no longer exists narrows to nothing rather
            // than being ignored — it is a real filter, just an empty one.
            ->where('filters.property', 99999)
            ->missing('filters.payment_status')
            ->missing('filters.expiry')
            ->missing('filters.tenant'));
});

it('narrows the panels to the filtered property', function () {
    $mine = dashboardLease([], '600.00');

    $other = Property::factory()->create(['name' => 'Elsewhere']);
    dashboardLease(['unit_id' => Unit::factory()->create(['property_id' => $other->id])->id], '900.00');

    CarbonImmutable::setTestNow(
        $this->calendar->today()->startOfMonth()->addDays(19)->setTime(12, 0)->utc(),
    );

    $this->actingAs($this->admin)->get("/admin?property={$this->property->id}")
        ->assertInertia(fn ($page) => $page
            ->where('kpis.outstanding_tenant', '600.00')
            ->has('panels.past_due', 1)
            ->where('panels.past_due.0.tenant_id', $mine->tenant_id));

    CarbonImmutable::setTestNow();
});

/*
 |--------------------------------------------------------------------------
 | The panels
 |--------------------------------------------------------------------------
 */

it('lists past-due accounts worst first, with a phone number to ring', function () {
    CarbonImmutable::setTestNow(
        $this->calendar->today()->startOfMonth()->addDays(19)->setTime(12, 0)->utc(),
    );

    $mild = dashboardLease(['grace_period_days' => 10], '100.00');
    $bad = dashboardLease(['grace_period_days' => 0], '900.00');
    $bad->tenant->forceFill(['phone' => '404-555-0117'])->save();

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->has('panels.past_due', 2)
            ->where('panels.past_due.0.tenant_id', $bad->tenant_id)
            ->where('panels.past_due.0.phone', '404-555-0117')
            ->where('panels.past_due.0.balance', '900.00')
            ->where('panels.past_due.1.tenant_id', $mild->tenant_id));

    CarbonImmutable::setTestNow();
});

it('puts emergency tickets at the top of the open-ticket panel', function () {
    $lease = dashboardLease();

    $routine = makeTicket($lease, ['is_emergency' => false, 'status' => Ticket::STATUS_TRIAGED]);
    $urgent = makeTicket($lease, ['is_emergency' => true, 'status' => Ticket::STATUS_ASSIGNED]);
    makeTicket($lease, ['status' => Ticket::STATUS_CLOSED]);

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->has('panels.tickets.items', 2)
            ->where('panels.tickets.items.0.id', $urgent->id)
            ->where('panels.tickets.items.1.id', $routine->id)
            ->where('panels.tickets.open_total', 2)
            ->where('panels.tickets.emergency_total', 1));
});

it('shows only leases ending inside the chosen window', function () {
    $today = $this->calendar->today();

    dashboardLease(['end_date' => $today->addDays(20)->toDateString()]);
    dashboardLease(['end_date' => $today->addDays(120)->toDateString()]);

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page->has('panels.expiring_leases', 1));

    $this->actingAs($this->admin)->get('/admin?expiry=180')
        ->assertInertia(fn ($page) => $page->has('panels.expiring_leases', 2));
});

it('lists recent payments newest first', function () {
    $lease = dashboardLease();

    Payment::factory()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'amount' => '100.00', 'submitted_at' => now()->subDays(3),
    ]);
    $latest = Payment::factory()->settled()->create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id,
        'amount' => '250.00', 'submitted_at' => now(),
    ]);

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->has('panels.recent_payments', 2)
            ->where('panels.recent_payments.0.id', $latest->id)
            ->where('panels.recent_payments.0.amount', '250.00'));
});

it('serialises every money figure as a string, never a number', function () {
    $lease = dashboardLease([], '600.00');
    $this->ledger->postPayment($lease, 'tenant', Money::fromString('100.00'), 'Part', null, 'cleared');

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];

            // I-10 all the way to the browser: a float here would be the one
            // place the money layer leaks.
            foreach (['collected_total', 'collected_tenant', 'collected_ha', 'outstanding_tenant', 'outstanding_ha'] as $key) {
                expect($props['kpis'][$key])->toBeString();
            }

            expect($props['panels']['recent_payments'])->each->toHaveKey('amount');
        });
});

function makeTicket(Lease $lease, array $overrides = []): Ticket
{
    static $sequence = 0;
    $sequence++;

    $ticket = new Ticket;
    $ticket->forceFill(array_merge([
        'ticket_number' => sprintf('T-2026-%04d', $sequence),
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'unit_id' => $lease->unit_id,
        'category' => 'plumbing',
        'description' => 'Water where it should not be.',
        'permission_to_enter' => true,
        'preferred_contact' => 'phone',
        'pets_present' => false,
        'date_began' => '2026-08-01',
        'is_emergency' => false,
        'urgency' => 'normal',
        'status' => Ticket::STATUS_SUBMITTED,
    ], $overrides))->save();

    return $ticket;
}
