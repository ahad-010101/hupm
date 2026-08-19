<?php

use App\Domain\Ledger\LedgerService;
use App\Models\Document;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Tenant dashboard and ledger  [WP-15A, FR-POR-01/02, UI §3.1/§3.4]
|--------------------------------------------------------------------------
|
| The screen the whole product is for, and the one place I-4 has to hold
| absolutely: a tenant must never see, or be sent, the Housing Authority
| portion of their rent.
|
| These tests assert against the serialised Inertia props rather than the
| rendered DOM. A component that filters correctly today is one refactor away
| from not; a payload that never carries the figure cannot leak it.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->ledger = app(LedgerService::class);
    $this->tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);
    $this->user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $property = Property::factory()->create([
        'name' => 'Peachtree House',
        'street_address' => '12 Peachtree Street',
        'city' => 'Atlanta',
        'state' => 'GA',
        'postal_code' => '30303',
    ]);

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create(['property_id' => $property->id, 'unit_number' => '2B'])->id,
        'tenant_id' => $this->tenant->id,
        'housing_authority_id' => HousingAuthority::factory()->create()->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '500.00',
        // The number that must never reach the browser.
        'ha_portion' => '700.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'is_subsidised' => true,
        'status' => 'active',
    ])->save();

    $this->actingAs($this->user);
});

/** Charge both payers for a period, the way WP-10 does. */
function chargeBothPayers(string $period = '2026-02', string $postedOn = '2026-02-01'): void
{
    test()->ledger->postCharge(
        test()->lease, 'rent', 'tenant', Money::fromString('500.00'),
        'Rent — February 2026', "por:{$period}:t", CarbonImmutable::parse($postedOn), $period,
    );
    test()->ledger->postCharge(
        test()->lease, 'rent', 'housing_authority', Money::fromString('700.00'),
        'Rent — February 2026', "por:{$period}:h", CarbonImmutable::parse($postedOn), $period,
    );
}

/** @return array<string, mixed> */
function portalProps(string $url): array
{
    $props = [];

    test()->get($url)->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/*
 |--------------------------------------------------------------------------
 | I-4 — the invariant this screen exists to protect
 |--------------------------------------------------------------------------
 */

it('AC-POR-01 sends no housing authority figure anywhere in the dashboard payload', function () {
    chargeBothPayers();

    $encoded = json_encode(portalProps('/portal'));

    // Every way the number could appear: the amount, the word, the column.
    expect($encoded)->not->toContain('700.00')
        ->not->toContain('housing_authority')
        ->not->toContain('ha_portion')
        ->not->toContain('1200.00')
        ->and(json_decode($encoded, true)['balances']['balance'])->toBe('500.00');
});

it('AC-POR-03 sends only tenant rows to the ledger, never the authority’s', function () {
    chargeBothPayers();

    $props = portalProps('/portal/ledger');
    $encoded = json_encode($props);

    expect($props['entries'])->toHaveCount(1)
        ->and($props['entries'][0]['charge'])->toBe('500.00')
        ->and($encoded)->not->toContain('700.00')
        ->not->toContain('housing_authority');
});

it('sends the tenant portion as the monthly rent, not the contract rent', function () {
    $props = portalProps('/portal');

    // BR-02: what the resident pays is their business. What the contract is
    // worth is not.
    expect($props['lease']['monthly_rent'])->toBe('500.00');
});

/*
 |--------------------------------------------------------------------------
 | What the dashboard answers
 |--------------------------------------------------------------------------
 */

it('shows balance and pending as two separate figures', function () {
    chargeBothPayers();

    $payment = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('200.00'), 'Payment submitted online',
    );

    $props = portalProps('/portal');

    // UI §3.1. One combined figure would be arithmetically defensible and
    // practically expensive: a tenant who cannot see their payment in flight
    // pays a second time.
    expect($props['balances']['balance'])->toBe('500.00')
        ->and($props['balances']['pending'])->toBe('200.00');
});

it('AC-POR-02 shows zero and the next due date, and offers no payment', function () {
    // Nothing charged, nothing owed.
    $props = portalProps('/portal');

    expect($props['balances']['balance'])->toBe('0.00')
        ->and($props['due']['state'])->toBe('paid_up')
        ->and($props['due']['next_due_on'])->not->toBeNull();

    // There is simply nothing to pay. The component reads that from the
    // balance rather than from a separate flag.
    expect($props['balances']['pending'])->toBe('0.00');
});

it('renders a credit as a credit, never as a minus figure', function () {
    $entry = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('120.00'), 'Overpayment', null, 'cleared',
    );

    $props = portalProps('/portal');

    // UI §7: the sign becomes the word "Credit" in <Money balance>, which is
    // covered by that component's own tests. What matters here is that the
    // props say a credit is what this is.
    expect($props['balances']['balance'])->toBe('-120.00')
        ->and($props['due']['state'])->toBe('in_credit');
});

it('gives the address, the lease dates and the manager’s number', function () {
    app(App\Support\Settings::class)->set('company.phone', '(404) 555-0142');
    app(App\Support\Settings::class)->flush();

    $props = portalProps('/portal');

    expect($props['address'])->toBe('12 Peachtree Street, unit 2B, Atlanta, GA 30303')
        ->and($props['lease']['starts_on'])->toBe('1 January 2026')
        ->and($props['lease']['expires_on'])->toBe('31 December 2026')
        ->and($props['manager']['phone'])->toBe('(404) 555-0142');
});

it('uses long-form dates, as the portal always does', function () {
    chargeBothPayers();

    // UI §8: the tenant portal spells the month out. Admin tables may not.
    expect(portalProps('/portal')['recentActivity'][0]['date'])->toBe('1 February 2026');
});

it('lists the five most recent things, newest first', function () {
    foreach (range(1, 7) as $month) {
        $period = sprintf('2026-%02d', $month);
        $this->ledger->postCharge(
            $this->lease, 'rent', 'tenant', Money::fromString('500.00'),
            "Rent — month {$month}", "many:{$period}", CarbonImmutable::parse("{$period}-01"), $period,
        );
    }

    $activity = portalProps('/portal')['recentActivity'];

    expect($activity)->toHaveCount(5)
        ->and($activity[0]['description'])->toBe('Rent — month 7');
});

it('shows the three most recent documents shared with this tenant', function () {
    foreach (range(1, 4) as $i) {
        Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'title' => "Document {$i}",
        ]);
    }

    Document::factory()->hiddenFromTenant()->create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Internal note',
    ]);

    $titles = collect(portalProps('/portal')['documents'])->pluck('title');

    expect($titles)->toHaveCount(3)
        ->and($titles)->not->toContain('Internal note');
});

/*
 |--------------------------------------------------------------------------
 | Management Review
 |--------------------------------------------------------------------------
 */

it('AC-DEL-02 keeps balance, history and documents visible during Management Review', function () {
    chargeBothPayers();
    $this->lease->forceFill(['delinquency_state' => 'management_review'])->save();

    $props = portalProps('/portal');

    expect($props['balances']['balance'])->toBe('500.00')
        ->and($props['lease']['in_management_review'])->toBeTrue()
        // BR-13: the account is under review, not switched off.
        ->and($props['canPay'])->toBeFalse();

    $this->get('/portal/ledger')->assertOk();
    $this->get('/portal/documents')->assertOk();
});

it('replaces the pay action with a way to reach a person', function () {
    chargeBothPayers();
    $this->lease->forceFill(['delinquency_state' => 'management_review'])->save();
    app(App\Support\Settings::class)->set('company.phone', '(404) 555-0142');
    app(App\Support\Settings::class)->flush();

    $props = portalProps('/portal');

    // Inertia serves a shell; the copy is rendered client-side. What the server
    // controls, and what the test can hold it to, is that the pay action is
    // refused and the office number is there to replace it.
    expect($props['canPay'])->toBeFalse()
        ->and($props['lease']['in_management_review'])->toBeTrue()
        ->and($props['manager']['phone'])->toBe('(404) 555-0142');
});

/*
 |--------------------------------------------------------------------------
 | When something is wrong
 |--------------------------------------------------------------------------
 */

it('hides the pay action rather than render a balance it cannot compute', function () {
    chargeBothPayers();

    // The database will not hold a malformed DECIMAL, so the realistic shape of
    // this is the calculator itself failing — a dropped connection mid-request,
    // or a row Money refuses after an import.
    $this->mock(App\Domain\Ledger\BalanceCalculator::class, function ($mock) {
        $mock->shouldReceive('tenantBalance')->andThrow(new RuntimeException('ledger unreadable'));
        $mock->shouldReceive('pendingPayments')->andThrow(new RuntimeException('ledger unreadable'));
    });

    $props = portalProps('/portal');

    // UI §3.1: never render a possibly-wrong balance, and take the pay button
    // with it. A figure someone might pay against has to be right or absent.
    expect($props['balances']['balance'])->toBeNull()
        ->and($props['balances']['error'])->toContain('could not work out your balance')
        ->and($props['canPay'])->toBeFalse();
});

it('tells a user with no tenancy something useful instead of failing', function () {
    $orphan = User::factory()->create(['role' => 'tenant', 'tenant_id' => null]);

    $this->actingAs($orphan)->get('/portal')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orphaned', true));
});

it('keeps an admin out of the tenant portal', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get('/portal')->assertForbidden();

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get('/portal/ledger')->assertForbidden();
});

/*
 |--------------------------------------------------------------------------
 | The ledger and its statement
 |--------------------------------------------------------------------------
 */

it('carries a running balance that ends where the dashboard says it does', function () {
    chargeBothPayers('2026-02', '2026-02-01');
    chargeBothPayers('2026-03', '2026-03-01');

    $entry = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('300.00'), 'Cheque 1001', null, 'cleared',
        CarbonImmutable::parse('2026-03-05'),
    );

    $props = portalProps('/portal/ledger');
    $rows = $props['entries'];

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['running'])->toBe('500.00')
        ->and($rows[1]['running'])->toBe('1000.00')
        ->and($rows[2]['payment'])->toBe('300.00')
        ->and($rows[2]['running'])->toBe('700.00')
        // The last row equals the balance, always. A separately-derived figure
        // would eventually disagree.
        ->and($props['balance'])->toBe('700.00');
});

it('narrows what is shown without changing what is counted', function () {
    chargeBothPayers('2026-02', '2026-02-01');
    chargeBothPayers('2026-03', '2026-03-01');

    $rows = portalProps('/portal/ledger?from=2026-03-01')['entries'];

    // One row shown, but its running balance still includes February — a
    // filtered statement that restarted the total would be wrong about the
    // account.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['running'])->toBe('1000.00');
});

it('ignores a malformed date rather than erroring on someone’s own history', function () {
    chargeBothPayers();

    $this->get('/portal/ledger?from=not-a-date')->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries', 1));
});

it('never calls an abandoned attempt "processing"', function () {
    chargeBothPayers();

    $entry = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('400.00'), 'Payment submitted online',
    );
    // The gateway was unreachable, or they closed the tab (WP-13).
    $this->ledger->transitionStatus($entry, 'void');

    $dashboard = portalProps('/portal');
    $ledger = portalProps('/portal/ledger');

    // Nothing left their account, so it is not their history. Found by looking
    // at a real dashboard: four abandoned sandbox attempts all read
    // "· processing", which is money a tenant would think was on its way.
    expect($dashboard['recentActivity'])->toHaveCount(1)
        ->and($ledger['entries'])->toHaveCount(1)
        ->and(json_encode($ledger))->not->toContain('400.00');
});

it('says a returned payment was returned, not that it is processing', function () {
    chargeBothPayers();

    $entry = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('500.00'), 'Payment received', null, 'cleared',
    );
    $this->ledger->transitionStatus($entry, 'returned');

    $row = collect(portalProps('/portal/ledger')['entries'])->firstWhere('status', 'returned');

    // A return is a fact the tenant has to be told plainly — and never with the
    // word "failed" (UI §8).
    expect($row['state'])->toBe('returned by your bank')
        ->and($row['counts'])->toBeFalse();
});

it('shows a pending payment without letting it move the balance', function () {
    chargeBothPayers();
    $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('500.00'), 'Payment submitted online');

    $rows = portalProps('/portal/ledger')['entries'];

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['counts'])->toBeFalse()
        ->and($rows[1]['state'])->toBe('processing')
        // BR-05: visible, but it has not happened yet.
        ->and($rows[0]['running'])->toBe('500.00');
});

it('API-POR-03 downloads a statement as a PDF', function () {
    chargeBothPayers();

    $response = $this->get('/portal/ledger/export');

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $body = $response->getContent();

    expect(substr($body, 0, 4))->toBe('%PDF')
        ->and(strlen($body))->toBeGreaterThan(1000);
});

it('records that a statement was taken', function () {
    chargeBothPayers();

    $this->get('/portal/ledger/export');

    // An ordinary act, and also the document someone brings to a hearing.
    expect(DB::table('audit_logs')->where('action', 'portal.statement.downloaded')->exists())
        ->toBeTrue();
});
