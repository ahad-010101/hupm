<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Payments\PaymentRecordingService;
use App\Domain\Reporting\ReportRegistry;
use App\Models\HousingAuthority;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Reports  [WP-27, FR-ADM-02, AC-ADM-03, API-ADM-32…35]
|--------------------------------------------------------------------------
|
| Every one of these reports is a sum over ledger rows, which makes them all
| restatements of a balance. So the assertions that matter are the ones that
| reconcile: a rent roll total against the ledger for that period, arrears
| buckets against what is owed, the Section 8 portions against contract rent.
|
| A report that renders is not a report that is right, and the failure mode is
| quiet — a wrong total looks exactly like a right one.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);
    $this->reports = app(ReportRegistry::class);
    $this->calendar = app(BusinessCalendar::class);
    $this->period = $this->calendar->currentPeriod();

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->property = Property::factory()->create(['name' => 'Peachtree Court']);
    $this->authority = HousingAuthority::factory()->create(['name' => 'Atlanta Housing']);
});

function reportLease(string $tenantPortion = '600.00', string $haPortion = '600.00'): Lease
{
    $total = Money::fromString($tenantPortion)->plus(Money::fromString($haPortion));

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create(['property_id' => test()->property->id])->id,
        'tenant_id' => Tenant::factory()->create()->id,
        'housing_authority_id' => test()->authority->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => $total->toDecimalString(),
        'tenant_portion' => $tenantPortion,
        'ha_portion' => $haPortion,
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => Lease::STATUS_ACTIVE,
    ])->save();

    return $lease;
}

function reportCharge(Lease $lease, string $amount, string $payer = 'tenant', ?string $period = null, ?string $postedOn = null): void
{
    $period ??= test()->period;

    test()->ledger->postCharge(
        $lease, 'rent', $payer, Money::fromString($amount),
        'Rent', "rep:{$lease->id}:{$period}:{$payer}",
        CarbonImmutable::parse($postedOn ?? $period.'-01'), $period,
    );
}

function reportMoney(string $value): Money
{
    return Money::fromString($value);
}

/*
 |--------------------------------------------------------------------------
 | AC-ADM-03 — the rent roll reconciles to the ledger
 |--------------------------------------------------------------------------
 */

it('AC-ADM-03 totals a rent roll to exactly the charges posted in that period', function () {
    reportCharge(reportLease(), '600.00');
    reportCharge(reportLease('450.00', '750.00'), '450.00');

    $report = $this->reports->build('rent-roll', ['period' => $this->period]);

    $ledger = DB::table('ledger_entries')
        ->where('period', $this->period)
        ->whereIn('type', ['charge', 'adjustment'])
        ->whereIn('status', LedgerService::BALANCE_AFFECTING)
        ->sum('amount');

    expect(reportMoney($report->totals['charged'])->toDecimalString())
        ->toBe(reportMoney((string) $ledger)->toDecimalString());
});

it('reads charged from the ledger, not from the headline rent on the lease', function () {
    $lease = reportLease('600.00', '600.00');

    // Half a month, as proration would produce on a mid-month move-in. Taking
    // the figure from the lease would report $600 and be wrong in exactly the
    // case somebody checks.
    reportCharge($lease, '300.00');

    $report = $this->reports->build('rent-roll', ['period' => $this->period]);

    expect($report->totals['charged'])->toBe('300.00')
        ->and($report->rows[0]['tenant_charged'])->toBe('300.00');
});

it('counts a payment against the month it was for, not the month it arrived', function () {
    $lease = reportLease();
    reportCharge($lease, '600.00', 'tenant', $this->period);

    // Paid late, in a later month. The rent roll asks how much of that month's
    // rent was ever met — so it belongs to the period charged.
    app(PaymentRecordingService::class)->record(
        lease: $lease,
        payer: 'tenant',
        amount: Money::fromString('600.00'),
        receivedOn: $this->calendar->today(),
        method: 'cheque',
        idempotencyKey: (string) Str::uuid(),
    );

    $report = $this->reports->build('rent-roll', ['period' => $this->period]);

    expect($report->totals['received'])->toBe('600.00')
        ->and($report->totals['outstanding'])->toBe('0.00');
});

it('renders an empty rent roll for a month with no activity rather than failing', function () {
    reportCharge(reportLease(), '600.00');

    $report = $this->reports->build('rent-roll', ['period' => '2019-01']);

    expect($report->rows)->toBeEmpty()
        ->and($report->totals['charged'])->toBe('0.00');
});

/*
 |--------------------------------------------------------------------------
 | Aged arrears
 |--------------------------------------------------------------------------
 */

it('buckets arrears by the age of each charge', function () {
    $lease = reportLease();
    $today = $this->calendar->today();

    reportCharge($lease, '100.00', 'tenant', '2026-08', $today->subDays(10)->toDateString());
    reportCharge($lease, '200.00', 'tenant', '2026-07', $today->subDays(45)->toDateString());
    reportCharge($lease, '300.00', 'tenant', '2026-06', $today->subDays(75)->toDateString());
    reportCharge($lease, '400.00', 'tenant', '2026-05', $today->subDays(200)->toDateString());

    $report = $this->reports->build('aged-arrears');

    expect($report->totals['b0'])->toBe('100.00')
        ->and($report->totals['b31'])->toBe('200.00')
        ->and($report->totals['b61'])->toBe('300.00')
        ->and($report->totals['b90'])->toBe('400.00');
});

it('sums its buckets to the total owed, and reconciles net to the portfolio balance', function () {
    reportCharge(reportLease(), '600.00');
    reportCharge(reportLease(), '250.00');

    $report = $this->reports->build('aged-arrears');

    $buckets = Money::sum(array_map(
        fn (string $k) => reportMoney($report->totals[$k]),
        ['b0', 'b31', 'b61', 'b90'],
    ));

    $portfolio = Money::sum(array_values($this->balances->balancesByTenant('tenant')));

    expect($buckets->toDecimalString())->toBe($report->totals['owed'])
        ->and($report->totals['net'])->toBe($portfolio->toDecimalString());
});

it('shows a credit separately instead of netting it into somebody else s arrears', function () {
    $behind = reportLease();
    reportCharge($behind, '600.00');

    $ahead = reportLease();
    reportCharge($ahead, '100.00');
    app(PaymentRecordingService::class)->record(
        lease: $ahead,
        payer: 'tenant',
        amount: Money::fromString('400.00'),   // $300 more than owed
        receivedOn: $this->calendar->today(),
        method: 'cheque',
        idempotencyKey: (string) Str::uuid(),
    );

    $report = $this->reports->build('aged-arrears');

    // Gross arrears stay gross. Folding the credit in would quietly reduce
    // what a resident who is genuinely behind appears to owe.
    expect($report->totals['owed'])->toBe('600.00')
        ->and($report->totals['credit'])->toBe('300.00')
        ->and($report->totals['net'])->toBe('300.00');
});

it('leaves the housing authority out of resident arrears', function () {
    $lease = reportLease();
    reportCharge($lease, '600.00', 'tenant');
    reportCharge($lease, '900.00', 'housing_authority');

    $report = $this->reports->build('aged-arrears');

    // BR-01: two separate obligations. An authority that has not remitted yet
    // is not a resident in arrears.
    expect($report->totals['owed'])->toBe('600.00');
});

/*
 |--------------------------------------------------------------------------
 | Section 8 split
 |--------------------------------------------------------------------------
 */

it('reconciles the two portions to the contract rent across every active lease', function () {
    reportLease('600.00', '600.00');
    reportLease('450.00', '750.00');
    reportLease('1200.00', '0.00');

    $report = $this->reports->build('section-8');

    expect(reportMoney($report->totals['tenant_portion'])->plus(reportMoney($report->totals['ha_portion']))->toDecimalString())
        ->toBe($report->totals['contract']);
});

it('warns when a lease has portions that do not sum to its contract rent', function () {
    $lease = reportLease();

    // Written past the validation, as a lease created before the rule existed
    // would have been. This report is the only place that would surface it.
    $lease->forceFill(['tenant_portion' => '500.00'])->save();

    $report = $this->reports->build('section-8');

    expect(implode(' ', $report->notes))->toContain('do not sum to the contract rent');
});

/*
 |--------------------------------------------------------------------------
 | Collections
 |--------------------------------------------------------------------------
 */

it('counts collections on the day the money cleared', function () {
    $lease = reportLease();
    reportCharge($lease, '600.00');

    $this->ledger->postPayment(
        $lease, 'tenant', Money::fromString('600.00'), 'Cheque',
        null, 'cleared', $this->calendar->today(),
    );

    // Pending is not collected, however recently it was submitted (I-6).
    $this->ledger->postPayment(
        $lease, 'tenant', Money::fromString('999.00'), 'Still in transit',
        null, 'pending', $this->calendar->today(),
    );

    $report = $this->reports->build('collections');
    $thisMonth = collect($report->rows)->firstWhere('month', $this->calendar->today()->format('F Y'));

    expect($thisMonth['collected'])->toBe('600.00');
});

/*
 |--------------------------------------------------------------------------
 | The screen and the exports
 |--------------------------------------------------------------------------
 */

it('renders every report on the screen', function () {
    reportCharge(reportLease(), '600.00');

    foreach (['rent-roll', 'collections', 'aged-arrears', 'section-8', 'payments'] as $key) {
        $this->actingAs($this->admin)->get("/admin/reports/{$key}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports/Index')
                ->where('active', $key)
                ->has('report.columns')
                ->has('report.rows'));
    }
});

it('404s on a report that does not exist rather than quietly showing another', function () {
    // Returning the rent roll when somebody asked for arrears puts the wrong
    // figures under the right heading.
    $this->actingAs($this->admin)->get('/admin/reports/invented')->assertNotFound();
});

it('exports a spreadsheet Excel can actually read', function () {
    reportCharge(reportLease(), '600.00');

    $response = $this->actingAs($this->admin)->get('/admin/reports/rent-roll/export?format=csv');

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $body = $response->streamedContent();

    // The BOM is what stops Excel on Windows reading UTF-8 as Windows-1252 and
    // mangling every accented name in the portfolio.
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($body)->toContain('Rent roll')
        // Money as a bare decimal, so the cell arrives as a number that sums.
        ->and($body)->toContain('600.00')
        ->and($body)->not->toContain('$600.00');
});

it('exports a PDF', function () {
    reportCharge(reportLease(), '600.00');

    $response = $this->actingAs($this->admin)->get('/admin/reports/rent-roll/export?format=pdf');

    $response->assertOk();

    // dompdf hands back a whole document, not a stream — the CSV streams
    // because it can be written row by row, and a PDF cannot.
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF')
        ->and($response->headers->get('content-type'))->toContain('application/pdf');
});

it('refuses a format it does not produce', function () {
    $this->actingAs($this->admin)->get('/admin/reports/rent-roll/export?format=xlsx')->assertNotFound();
});

it('audits an export, because a report leaving the building is a different act', function () {
    reportCharge(reportLease(), '600.00');

    $this->actingAs($this->admin)->get('/admin/reports/aged-arrears/export?format=csv')->streamedContent();

    // These carry every resident's name and what they owe. "Who exported the
    // arrears list, and when" gets asked after the fact or not at all.
    expect(DB::table('audit_logs')->where('action', 'report.exported')->exists())->toBeTrue();
});

it('keeps residents out of the reports entirely', function () {
    $tenant = Tenant::factory()->create();
    $resident = User::factory()->create(['role' => 'tenant', 'tenant_id' => $tenant->id]);

    // I-4 in its bluntest form: the Section 8 report names the authority
    // portion for every lease in the portfolio.
    $this->actingAs($resident)->get('/admin/reports/section-8')->assertForbidden();
    $this->actingAs($resident)->get('/admin/reports/section-8/export?format=csv')->assertForbidden();
});
