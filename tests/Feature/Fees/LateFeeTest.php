<?php

use App\Domain\Fees\LateFeeService;
use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Notifications\NotificationTemplate;
use App\Domain\Payments\AllocationService;
use App\Jobs\PostLateFees;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Late fee automation  [WP-23, FR-FEE-01, BR-06…BR-09]
|--------------------------------------------------------------------------
|
| Every criterion here is about money going to the right party. A late fee
| charged to the Housing Authority is both wrong and unrecoverable — the
| authority pays on its own schedule under the HAP contract and will simply
| not pay it — and a fee charged to a tenant who paid on time is the kind of
| mistake that ends up in front of a magistrate.
|
| Rent is due on the 1st with 5 days grace, so 7 February is late and
| 5 February is not.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);
    $this->fees = app(LateFeeService::class);
    $this->settings = app(Settings::class);

    // [GATE] Off by default. Every test that expects a fee turns it on
    // explicitly, which keeps the default honest.
    $this->settings->set('fees.automation_enabled', true);
    $this->settings->flush();

    $this->tenant = Tenant::factory()->create();
});

function feeLease(array $overrides = []): Lease
{
    $lease = new Lease;
    $lease->forceFill(array_merge([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => test()->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '700.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'late_fee_flat' => '50.00',
        'late_fee_daily' => '0.00',
        'late_fee_max' => '75.00',
        'status' => 'active',
    ], $overrides))->save();

    return $lease;
}

/** Charge rent for a period, for either payer. */
function chargeRent(Lease $lease, string $payer, string $amount, string $period = '2026-02'): LedgerEntry
{
    return test()->ledger->postCharge(
        $lease, 'rent', $payer, Money::fromString($amount),
        'Rent — February 2026', "fee:{$lease->id}:{$period}:{$payer}",
        CarbonImmutable::parse($period.'-01'), $period,
    );
}

/** A settled payment, allocated the way WP-11/WP-12 would. */
function payInFull(Lease $lease, string $payer, string $amount): void
{
    $payment = Payment::factory()->settled()->create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'payer' => $payer,
        'amount' => $amount,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $entry = test()->ledger->postPayment(
        $lease, $payer, Money::fromString($amount), 'Payment', $payment->id,
    );
    test()->ledger->transitionStatus($entry, 'cleared');

    app(AllocationService::class)->allocate($payment);
}

function postFeesOn(Lease $lease, string $date): int
{
    return test()->fees->postFor($lease, CarbonImmutable::parse($date));
}

function feeRows(): Collection
{
    return LedgerEntry::where('category', 'late_fee')->orderBy('id')->get();
}

/*
 |--------------------------------------------------------------------------
 | Which obligation a fee attaches to
 |--------------------------------------------------------------------------
 */

it('AC-FEE-01 charges the tenant obligation when the authority portion is fully paid', function () {
    $lease = feeLease();
    chargeRent($lease, 'tenant', '500.00');
    chargeRent($lease, 'housing_authority', '700.00');
    payInFull($lease, 'housing_authority', '700.00');

    expect(postFeesOn($lease, '2026-02-07'))->toBe(1);

    $fee = feeRows()->sole();

    // BR-06 / I-7. The authority is not late; it pays on its own schedule under
    // the HAP contract, and a fee charged to it would never be recovered.
    expect($fee->payer)->toBe('tenant')
        ->and($fee->amount->toDecimalString())->toBe('50.00')
        ->and($fee->period)->toBe('2026-02')
        ->and($this->balances->haBalance($this->tenant->id)->toDecimalString())->toBe('0.00');
});

it('AC-FEE-04 charges nothing when the tenant paid on time, whatever the authority owes', function () {
    $lease = feeLease();
    chargeRent($lease, 'tenant', '500.00');
    chargeRent($lease, 'housing_authority', '700.00');

    // The resident paid their share inside grace. The authority has paid
    // nothing — which is between us and the authority.
    payInFull($lease, 'tenant', '500.00');

    expect(postFeesOn($lease, '2026-02-20'))->toBe(0)
        ->and(feeRows())->toBeEmpty()
        ->and($this->balances->haBalance($this->tenant->id)->toDecimalString())->toBe('700.00');
});

it('never charges a fee against the housing authority payer', function () {
    $lease = feeLease(['late_fee_daily' => '5.00', 'late_fee_max' => null]);
    chargeRent($lease, 'tenant', '500.00');
    chargeRent($lease, 'housing_authority', '700.00');

    postFeesOn($lease, '2026-03-01');

    expect(feeRows())->not->toBeEmpty()
        ->and(feeRows()->pluck('payer')->unique()->all())->toBe(['tenant']);
});

/*
 |--------------------------------------------------------------------------
 | Timing
 |--------------------------------------------------------------------------
 */

it('charges nothing inside the grace period', function () {
    $lease = feeLease();
    chargeRent($lease, 'tenant', '500.00');

    // Due the 1st, five days grace: the 6th is the last free day.
    expect(postFeesOn($lease, '2026-02-05'))->toBe(0)
        ->and(postFeesOn($lease, '2026-02-06'))->toBe(0)
        ->and(postFeesOn($lease, '2026-02-07'))->toBe(1);
});

it('honours a per-lease grace period rather than a portfolio default', function () {
    $generous = feeLease(['grace_period_days' => 15]);
    chargeRent($generous, 'tenant', '500.00');

    // BR-07: every parameter is on the lease.
    expect(postFeesOn($generous, '2026-02-10'))->toBe(0)
        ->and(postFeesOn($generous, '2026-02-17'))->toBe(1);
});

it('assesses each period on its own, not on the overall balance', function () {
    $lease = feeLease(['late_fee_max' => '50.00']);
    chargeRent($lease, 'tenant', '500.00', '2026-02');
    chargeRent($lease, 'tenant', '500.00', '2026-03');

    // February is settled by the payment; March is not. One combined balance
    // figure could not tell these apart.
    payInFull($lease, 'tenant', '500.00');

    postFeesOn($lease, '2026-03-20');

    expect(feeRows()->pluck('period')->all())->toBe(['2026-03']);
});

/*
 |--------------------------------------------------------------------------
 | The cap
 |--------------------------------------------------------------------------
 */

it('AC-FEE-02 posts nothing once the lease maximum is reached', function () {
    $lease = feeLease(['late_fee_flat' => '75.00', 'late_fee_max' => '75.00']);
    chargeRent($lease, 'tenant', '500.00');

    expect(postFeesOn($lease, '2026-02-07'))->toBe(1)
        ->and(postFeesOn($lease, '2026-02-20'))->toBe(0)
        ->and(feeRows())->toHaveCount(1);
});

it('BR-08 trims the last fee to land exactly on the maximum', function () {
    $lease = feeLease([
        'late_fee_flat' => '50.00',
        'late_fee_daily' => '10.00',
        'late_fee_max' => '75.00',
    ]);
    chargeRent($lease, 'tenant', '500.00');

    postFeesOn($lease, '2026-02-28');

    // $50 flat + $10 + $10 would be $70; the next $10 is trimmed to $5.
    expect(feeRows()->sum(fn ($f) => $f->amount->minor))->toBe(7500)
        ->and(feeRows()->last()->amount->toDecimalString())->toBe('5.00');
});

it('treats a null maximum as uncapped, exactly as the schema says', function () {
    $lease = feeLease(['late_fee_daily' => '5.00', 'late_fee_max' => null]);
    chargeRent($lease, 'tenant', '500.00');

    postFeesOn($lease, '2026-02-17');

    // Flat $50, plus a $5 charge for each of 7–17 February inclusive. The day
    // the job runs counts: grace ended on the 6th, so the tenant is late on the
    // 7th and late again on the 17th.
    expect(feeRows())->toHaveCount(12)
        ->and(feeRows()->sum(fn ($f) => $f->amount->minor))->toBe(10500);
});

it('caps each period separately, not the account as a whole', function () {
    $lease = feeLease(['late_fee_max' => '50.00']);
    chargeRent($lease, 'tenant', '500.00', '2026-02');
    chargeRent($lease, 'tenant', '500.00', '2026-03');

    postFeesOn($lease, '2026-03-20');

    // BR-08 caps a charge period. Two unpaid months are two late rents.
    expect(feeRows())->toHaveCount(2)
        ->and(feeRows()->pluck('period')->all())->toBe(['2026-02', '2026-03']);
});

/*
 |--------------------------------------------------------------------------
 | Idempotency
 |--------------------------------------------------------------------------
 */

it('AC-FEE-03 charges one day when run twice in a day', function () {
    $lease = feeLease(['late_fee_daily' => '5.00', 'late_fee_max' => null]);
    chargeRent($lease, 'tenant', '500.00');

    postFeesOn($lease, '2026-02-08');
    $second = postFeesOn($lease, '2026-02-08');

    expect($second)->toBe(0)
        // Flat, plus the 7th and the 8th. Not six rows.
        ->and(feeRows())->toHaveCount(3);
});

it('BR-09 posts the flat fee once per period however often the job runs', function () {
    $lease = feeLease();
    chargeRent($lease, 'tenant', '500.00');

    foreach (['2026-02-07', '2026-02-08', '2026-02-09', '2026-02-20'] as $day) {
        postFeesOn($lease, $day);
    }

    expect(feeRows())->toHaveCount(1)
        ->and(feeRows()->sole()->charge_key)->toBe("{$lease->id}:latefee_flat:2026-02");
});

it('D-01 lets a month of daily fees coexist under the unique index', function () {
    $lease = feeLease(['late_fee_flat' => '0.00', 'late_fee_daily' => '2.00', 'late_fee_max' => null]);
    chargeRent($lease, 'tenant', '500.00');

    postFeesOn($lease, '2026-02-28');

    // The regression the deviation exists for: the specified index permitted
    // exactly ONE late_fee row per lease per month.
    expect(feeRows())->toHaveCount(22)
        ->and(feeRows()->pluck('charge_key')->unique())->toHaveCount(22);
});

it('charges the days it missed after the job did not run', function () {
    $lease = feeLease(['late_fee_flat' => '0.00', 'late_fee_daily' => '5.00', 'late_fee_max' => null]);
    chargeRent($lease, 'tenant', '500.00');

    // Three days of silence, then one run. The fee is owed under the lease,
    // not under our cron (the AC-CHG-04 principle).
    postFeesOn($lease, '2026-02-10');

    expect(feeRows()->pluck('posted_on')->map->toDateString()->all())
        ->toBe(['2026-02-07', '2026-02-08', '2026-02-09', '2026-02-10']);
});

/*
 |--------------------------------------------------------------------------
 | The gate
 |--------------------------------------------------------------------------
 */

it('GATE posts nothing at all while automation is off', function () {
    $this->settings->set('fees.automation_enabled', false);
    $this->settings->flush();

    $lease = feeLease();
    chargeRent($lease, 'tenant', '500.00');

    // Ships this way, and stays this way until an attorney has read the fee
    // language (timeline §5).
    expect(postFeesOn($lease, '2026-02-28'))->toBe(0)
        ->and(feeRows())->toBeEmpty()
        ->and($this->fees->isEnabled())->toBeFalse();
});

it('is off by default, without anything having to set it', function () {
    DB::table('settings')->where('key', 'fees.automation_enabled')->delete();
    $this->settings->flush();

    // The default has to be safe on its own, not because a seeder said so.
    expect($this->fees->isEnabled())->toBeFalse();
});

it('still shows what it would charge while switched off', function () {
    $this->settings->set('fees.automation_enabled', false);
    $this->settings->flush();

    $lease = feeLease();
    chargeRent($lease, 'tenant', '500.00');

    // The attorney doing the review needs to see what it would do before
    // anyone agrees to switch it on.
    $preview = $this->fees->preview($lease, CarbonImmutable::parse('2026-02-07'));

    expect($preview)->toHaveCount(1)
        ->and($preview[0]->amount->toDecimalString())->toBe('50.00')
        ->and(feeRows())->toBeEmpty();
});

it('records the skipped run rather than looking like a broken cron', function () {
    $this->settings->set('fees.automation_enabled', false);
    $this->settings->flush();

    $lease = feeLease();
    chargeRent($lease, 'tenant', '500.00');

    app(PostLateFees::class)->handle(
        app(LateFeeService::class),
        app(BusinessCalendar::class),
        app(AuditLogger::class),
    );

    // "Switched off" and "cron is broken" are different facts and must not look
    // the same in job_runs.
    expect(DB::table('job_runs')->where('job_name', PostLateFees::class)->value('status'))->toBe('success')
        ->and(DB::table('audit_logs')->where('action', 'fees.late.skipped')->exists())->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | Leases that should be left alone
 |--------------------------------------------------------------------------
 */

it('charges nothing on a lease with no fee configured', function () {
    $lease = feeLease(['late_fee_flat' => '0.00', 'late_fee_daily' => '0.00']);
    chargeRent($lease, 'tenant', '500.00');

    // Not an oversight to correct on the client's behalf.
    expect(postFeesOn($lease, '2026-03-01'))->toBe(0);
});

it('charges nothing on an ended lease', function () {
    $lease = feeLease();
    chargeRent($lease, 'tenant', '500.00');
    $lease->forceFill(['status' => 'ended'])->save();

    expect(postFeesOn($lease->fresh(), '2026-02-28'))->toBe(0);
});

it('stops charging once the rent is paid', function () {
    $lease = feeLease(['late_fee_daily' => '5.00', 'late_fee_max' => null]);
    chargeRent($lease, 'tenant', '500.00');

    postFeesOn($lease, '2026-02-09');
    $before = feeRows()->count();

    // Pays the rent and the fees so far.
    payInFull($lease, 'tenant', '1000.00');
    postFeesOn($lease, '2026-02-20');

    expect(feeRows())->toHaveCount($before);
});

/*
 |--------------------------------------------------------------------------
 | Telling the tenant
 |--------------------------------------------------------------------------
 */

it('emails once per run, not once per daily fee', function () {
    $lease = feeLease(['late_fee_daily' => '5.00', 'late_fee_max' => null]);
    chargeRent($lease, 'tenant', '500.00');

    postFeesOn($lease, '2026-02-28');

    // Twenty-three ledger rows and one thing that happened. Twenty-three emails
    // would be the system shouting at someone who already knows.
    expect(feeRows()->count())->toBeGreaterThan(10)
        ->and(DB::table('notification_logs')
            ->where('template', NotificationTemplate::LateFeePosted->value)->count())->toBe(1);
});

it('runs the whole portfolio and keeps going past one bad lease', function () {
    $good = feeLease();
    chargeRent($good, 'tenant', '500.00');

    $second = feeLease(['unit_id' => Unit::factory()->create()->id]);
    chargeRent($second, 'tenant', '500.00', '2026-02');

    CarbonImmutable::setTestNow('2026-02-20 12:00:00');
    Carbon::setTestNow('2026-02-20 12:00:00');

    $posted = app(PostLateFees::class)->handle(
        app(LateFeeService::class),
        app(BusinessCalendar::class),
        app(AuditLogger::class),
    );

    CarbonImmutable::setTestNow();
    Carbon::setTestNow();

    expect($posted)->toBe(2)
        ->and(DB::table('job_runs')->where('job_name', PostLateFees::class)->value('records_processed'))->toBe(2);
});
