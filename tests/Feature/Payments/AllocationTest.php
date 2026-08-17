<?php

use App\Domain\Charges\ChargePostingService;
use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Domain\Payments\AllocationOrderRegistry;
use App\Domain\Payments\AllocationService;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Payment allocation — the waterfall  [WP-11, FR-LED-03, Q-1, Q-8]
|--------------------------------------------------------------------------
|
| What a payment paid is not arithmetic anybody should have to redo. These
| tests pin the answer for the two orders the client may choose between, and
| for the cases where money does not divide neatly: an overpayment, a bounced
| payment, a payment from the wrong payer.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);
    $this->allocations = app(AllocationService::class);
    $this->settings = app(Settings::class);

    $this->tenant = Tenant::factory()->create();

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '700.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();
});

/** A posted charge on a given date. */
function outstanding(
    string $amount,
    string $postedOn,
    string $category = 'rent',
    string $payer = 'tenant',
): LedgerEntry {
    static $n = 0;

    return test()->ledger->postCharge(
        test()->lease,
        $category,
        $payer,
        Money::fromString($amount),
        ucfirst(str_replace('_', ' ', $category))." — {$postedOn}",
        'alloc:'.(++$n),
        CarbonImmutable::parse($postedOn),
    );
}

/** A settled payment with its cleared ledger entry, ready to allocate. */
function settledPayment(string $amount, string $payer = 'tenant'): Payment
{
    $payment = Payment::factory()->settled()->create([
        'lease_id' => test()->lease->id,
        'tenant_id' => test()->tenant->id,
        'payer' => $payer,
        'amount' => $amount,
    ]);

    $entry = test()->ledger->postPayment(
        test()->lease, $payer, Money::fromString($amount), 'Payment received', $payment->id,
    );
    test()->ledger->transitionStatus($entry, 'cleared');

    return $payment;
}

/** charge id => amount applied, in the order it was applied. */
function appliedAmounts(Payment $payment): array
{
    return test()->allocations
        ->allocationsFor($payment)
        ->mapWithKeys(fn (PaymentAllocation $a) => [$a->charge_entry_id => $a->amount->toDecimalString()])
        ->all();
}

/*
 |--------------------------------------------------------------------------
 | The waterfall
 |--------------------------------------------------------------------------
 */

it('AC-LED-05 applies $200 to a January fee then February rent under oldest_charge_first', function () {
    // The specification's own worked example.
    $fee = outstanding('50.00', '2026-01-06', 'late_fee');
    $rent = outstanding('500.00', '2026-02-01');

    $payment = settledPayment('200.00');
    $applied = $this->allocations->allocate($payment);

    expect($applied)->toHaveCount(2)
        ->and(appliedAmounts($payment))->toBe([
            $fee->id => '50.00',
            $rent->id => '150.00',
        ])
        // Both recorded — the point of the join table is that this is
        // answerable later without redoing the arithmetic.
        ->and(PaymentAllocation::count())->toBe(2)
        ->and($this->allocations->unallocated($payment)->toDecimalString())->toBe('0.00');
});

it('leaves the rest of a part-paid charge outstanding', function () {
    $rent = outstanding('500.00', '2026-02-01');

    $this->allocations->allocate(settledPayment('150.00'));

    $charges = $this->balances->outstandingCharges($this->tenant->id);

    expect($charges)->toHaveCount(1)
        ->and($charges->first()->id)->toBe($rent->id)
        ->and($charges->first()->outstanding->toDecimalString())->toBe('350.00')
        ->and($charges->first()->allocated->toDecimalString())->toBe('150.00');
});

it('allocation does not move the balance — only the ledger does', function () {
    outstanding('500.00', '2026-02-01');
    $payment = settledPayment('200.00');

    $before = $this->balances->tenantBalance($this->tenant->id);
    $this->allocations->allocate($payment);
    $after = $this->balances->tenantBalance($this->tenant->id);

    // I-1: the balance is a SUM over ledger rows. An allocation records where
    // money went; it is not money itself, and a waterfall that shifted a
    // balance would be a second source of truth.
    expect($before->toDecimalString())->toBe('300.00')
        ->and($after->toDecimalString())->toBe('300.00');
});

/*
 |--------------------------------------------------------------------------
 | Q-1 — the order is configuration
 |--------------------------------------------------------------------------
 */

it('switching payment.allocation_order changes the allocation with no code change', function () {
    $january = outstanding('500.00', '2026-01-01');
    $february = outstanding('500.00', '2026-02-01');

    $this->settings->set('payment.allocation_order', 'newest_charge_first');
    $this->settings->flush();

    $payment = settledPayment('300.00');
    $this->allocations->allocate($payment);

    expect(appliedAmounts($payment))->toBe([$february->id => '300.00'])
        ->and($this->balances->outstandingCharges($this->tenant->id)->firstWhere('id', $january->id)
            ->outstanding->toDecimalString())->toBe('500.00');
});

it('GATE Q-1 rent_before_fees pays the rent before an older fee', function () {
    // The alternative with consequences: under oldest_charge_first this $200
    // would clear the January fee first and leave $350 of rent outstanding.
    // Rent outstanding is the figure a Georgia dispossessory turns on.
    $fee = outstanding('50.00', '2026-01-06', 'late_fee');
    $rent = outstanding('500.00', '2026-02-01');

    $this->settings->set('payment.allocation_order', 'rent_before_fees');
    $this->settings->flush();

    $payment = settledPayment('200.00');
    $this->allocations->allocate($payment);

    expect(appliedAmounts($payment))->toBe([$rent->id => '200.00'])
        ->and($this->balances->outstandingCharges($this->tenant->id)->firstWhere('id', $fee->id)
            ->outstanding->toDecimalString())->toBe('50.00');
});

it('refuses an unrecognised allocation order rather than falling back', function () {
    outstanding('500.00', '2026-02-01');

    $this->settings->set('payment.allocation_order', 'oldest_charge_frist');
    $this->settings->flush();

    // Failing open would apply every payment by a rule nobody chose, silently,
    // until a tenant disputed their balance.
    $this->allocations->allocate(settledPayment('200.00'));
})->throws(InvalidArgumentException::class, 'is not a known payment allocation order');

it('the shipped default is a real order', function () {
    // A default that does not resolve would take the whole payment path down on
    // the first settlement after go-live.
    $this->seed(SettingsSeeder::class);

    expect(AllocationOrderRegistry::keys())->toContain(AllocationOrderRegistry::DEFAULT)
        ->and(DB::table('settings')->where('key', 'payment.allocation_order')->value('value'))
        ->toBeIn(AllocationOrderRegistry::keys());
});

it('is deterministic — same charges, same allocation, whatever order they arrive in', function () {
    // Three charges sharing a date: rent, a utility and a fee all post on the
    // 1st. Without a tie-break MySQL may return them in any order and the same
    // payment would land differently between two runs.
    $rent = outstanding('500.00', '2026-02-01');
    $water = outstanding('45.00', '2026-02-01', 'utility');
    $fee = outstanding('50.00', '2026-02-01', 'late_fee');

    $charges = $this->balances->outstandingCharges($this->tenant->id);
    $order = app(AllocationOrderRegistry::class)->current();

    for ($run = 0; $run < 5; $run++) {
        expect($order->sort($charges->shuffle())->pluck('id')->all())
            ->toBe([$rent->id, $water->id, $fee->id]);
    }
});

/*
 |--------------------------------------------------------------------------
 | Q-8 — overpayment
 |--------------------------------------------------------------------------
 */

it('AC-LED-06 records the remainder as an unapplied credit and shows a negative balance', function () {
    $rent = outstanding('500.00', '2026-02-01');

    $payment = settledPayment('600.00');
    $this->allocations->allocate($payment);

    expect(appliedAmounts($payment))->toBe([$rent->id => '500.00'])
        ->and($this->allocations->unallocated($payment)->toDecimalString())->toBe('100.00')
        // A negative balance is a credit. The tenant UI renders it as
        // "Credit $100.00" in green, never as a minus figure.
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('-100.00')
        ->and($this->balances->outstandingCharges($this->tenant->id))->toBeEmpty();
});

it('GATE Q-8 credit_forward applies a standing credit to the next charge posted', function () {
    // Nothing owed yet; the tenant pays $600 anyway.
    $payment = settledPayment('600.00');
    $this->allocations->allocate($payment);

    expect($this->allocations->unallocated($payment)->toDecimalString())->toBe('600.00');

    // January rent posts. The credit has to meet it in the same transaction,
    // or WP-23 puts a late fee on rent that is already paid.
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
    app(ChargePostingService::class)->postFor($this->lease, CarbonImmutable::parse('2026-01-01'));
    CarbonImmutable::setTestNow();

    expect($this->allocations->unallocated($payment)->toDecimalString())->toBe('100.00')
        ->and($this->balances->outstandingCharges($this->tenant->id))->toBeEmpty();
});

it('GATE Q-8 refund does not let next month’s rent swallow money owed back', function () {
    $this->settings->set('payments.overpayment_behaviour', 'refund');
    $this->settings->flush();

    $payment = settledPayment('600.00');
    $this->allocations->allocate($payment);

    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
    app(ChargePostingService::class)->postFor($this->lease, CarbonImmutable::parse('2026-01-01'));
    CarbonImmutable::setTestNow();

    expect($this->allocations->unallocated($payment)->toDecimalString())->toBe('600.00')
        ->and($this->balances->outstandingCharges($this->tenant->id))->toHaveCount(1)
        // Someone has to send it back, and that has to be visible rather than
        // inferred from a negative balance.
        ->and(DB::table('audit_logs')->where('action', 'payment.refund_due')->exists())->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | Reversal — D-02
 |--------------------------------------------------------------------------
 */

it('a reversed allocation restores the charge to outstanding and stays queryable', function () {
    $rent = outstanding('500.00', '2026-02-01');
    $payment = settledPayment('500.00');
    $this->allocations->allocate($payment);

    expect($this->balances->outstandingCharges($this->tenant->id))->toBeEmpty();

    $reversed = $this->allocations->reverseFor($payment, 'ACH return R01 — insufficient funds');

    expect($reversed)->toBe(1)
        ->and($this->balances->outstandingCharges($this->tenant->id)->first()->outstanding->toDecimalString())
        ->toBe('500.00')
        // Evidence, not a delete. The history of a bounced payment is exactly
        // the history someone will need to explain.
        ->and(PaymentAllocation::count())->toBe(1)
        ->and(PaymentAllocation::first()->isReversed())->toBeTrue()
        ->and(PaymentAllocation::first()->chargeEntry->id)->toBe($rent->id);
});

it('refuses to reverse an allocation twice', function () {
    outstanding('500.00', '2026-02-01');
    $payment = settledPayment('500.00');
    $this->allocations->allocate($payment);

    $this->allocations->reverseFor($payment, 'Returned');
    $this->allocations->reverse(PaymentAllocation::first(), 'Returned again');
})->throws(InvalidArgumentException::class, 'already been reversed');

it('refuses to reverse an allocation without a reason', function () {
    outstanding('500.00', '2026-02-01');
    $payment = settledPayment('500.00');
    $this->allocations->allocate($payment);

    $this->allocations->reverse(PaymentAllocation::first(), '  ');
})->throws(InvalidArgumentException::class, 'requires a reason');

/*
 |--------------------------------------------------------------------------
 | The rules that protect the money
 |--------------------------------------------------------------------------
 */

it('I-6 refuses to allocate a payment that has not settled', function () {
    outstanding('500.00', '2026-02-01');

    $payment = Payment::factory()->create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'amount' => '200.00',
    ]);

    // Submitting is not paying. ACH takes 2–5 business days and can still be
    // returned afterwards.
    $this->allocations->allocate($payment);
})->throws(InvalidArgumentException::class, 'only a settled payment can be allocated');

it('AC-PAY-16 a housing authority payment never touches a tenant charge', function () {
    $tenantRent = outstanding('500.00', '2026-02-01', 'rent', 'tenant');
    $haRent = outstanding('700.00', '2026-02-01', 'rent', 'housing_authority');

    $payment = settledPayment('700.00', 'housing_authority');
    $this->allocations->allocate($payment);

    expect(appliedAmounts($payment))->toBe([$haRent->id => '700.00'])
        // BR-01: two separate obligations. Money never crosses between them.
        ->and($this->balances->outstandingCharges($this->tenant->id, 'tenant')->first()->id)
        ->toBe($tenantRent->id)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('a tenant payment never pays down the housing authority portion', function () {
    outstanding('500.00', '2026-02-01', 'rent', 'tenant');
    $haRent = outstanding('700.00', '2026-02-01', 'rent', 'housing_authority');

    $payment = settledPayment('900.00');
    $this->allocations->allocate($payment);

    expect(appliedAmounts($payment))->toHaveCount(1)
        ->and($this->allocations->unallocated($payment)->toDecimalString())->toBe('400.00')
        ->and($this->balances->outstandingCharges($this->tenant->id, 'housing_authority')->first()->id)
        ->toBe($haRent->id);
});

it('is idempotent — allocating the same payment twice writes nothing further', function () {
    outstanding('500.00', '2026-02-01');
    $payment = settledPayment('200.00');

    $this->allocations->allocate($payment);
    $second = $this->allocations->allocate($payment);

    // Settlement reconciliation re-reads a ten-day window (WP-14) and will see
    // the same payment on several consecutive runs.
    expect($second)->toBeEmpty()
        ->and(PaymentAllocation::count())->toBe(1)
        ->and(PaymentAllocation::first()->amount->toDecimalString())->toBe('200.00');
});

it('never allocates more than the payment', function () {
    outstanding('100.00', '2026-01-01');
    outstanding('100.00', '2026-02-01');
    outstanding('100.00', '2026-03-01');

    $payment = settledPayment('250.00');
    $this->allocations->allocate($payment);

    $total = Money::fromString((string) PaymentAllocation::sum('amount'));

    expect($total->toDecimalString())->toBe('250.00')
        ->and($this->balances->outstandingCharges($this->tenant->id)->last()->outstanding->toDecimalString())
        ->toBe('50.00');
});

it('ignores a charge that is already fully paid', function () {
    $january = outstanding('100.00', '2026-01-01');
    $february = outstanding('100.00', '2026-02-01');

    $this->allocations->allocate(settledPayment('100.00'));

    $second = settledPayment('100.00');
    $this->allocations->allocate($second);

    expect(appliedAmounts($second))->toBe([$february->id => '100.00'])
        ->and(PaymentAllocation::where('charge_entry_id', $january->id)->count())->toBe(1);
});
