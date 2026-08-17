<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Ledger engine  [WP-09, FR-LED-01/02/04, BR-01…BR-05]
|--------------------------------------------------------------------------
|
| The highest-risk package in the build. Everything downstream — fees,
| delinquency, payments, reporting — reads what this writes.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);

    $tenant = Tenant::factory()->create();
    $unit = Unit::factory()->create();

    $this->tenant = $tenant;
    $this->lease = Lease::query()->firstOrNew();
    $this->lease->forceFill([
        'unit_id' => $unit->id,
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '700.00',
        'status' => 'active',
    ])->save();
});

function charge(string $payer, string $amount, string $key, string $category = 'rent'): LedgerEntry
{
    return test()->ledger->postCharge(
        test()->lease, $category, $payer, Money::fromString($amount), ucfirst($category), $key,
    );
}

/*
 |--------------------------------------------------------------------------
 | Balances
 |--------------------------------------------------------------------------
 */

it('AC-LED-02 shows $300 and never references the housing authority portion', function () {
    // The specification's own worked example: $500 tenant + $700 HA charges,
    // and a $200 cleared tenant payment.
    charge('tenant', '500.00', 'k:rent:tenant');
    charge('housing_authority', '700.00', 'k:rent:ha');

    $payment = $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('200.00'), 'Payment');
    $this->ledger->transitionStatus($payment, 'cleared');

    $balance = $this->balances->tenantBalance($this->tenant->id);

    expect($balance->toDecimalString())->toBe('300.00')
        // I-4 / BR-02: the HA obligation is real but is not the tenant's.
        ->and($this->balances->haBalance($this->tenant->id)->toDecimalString())->toBe('700.00');
});

it('AC-LED-02 keeps the housing authority figure out of any tenant-facing payload', function () {
    charge('tenant', '500.00', 'k:rent:tenant');
    charge('housing_authority', '700.00', 'k:rent:ha');

    $entries = $this->balances->runningBalance($this->tenant->id);
    $payload = json_encode($entries->map(fn ($row) => [
        'amount' => (string) $row->entry->amount,
        'running' => (string) $row->running,
        'description' => $row->entry->description,
    ]));

    expect($payload)->not->toContain('700')
        ->and($entries)->toHaveCount(1);
});

it('AC-LED-03 excludes a pending payment from the balance', function () {
    charge('tenant', '500.00', 'k:rent:tenant');

    // BR-05. ACH takes 2–5 business days; submitting is not paying (I-6).
    $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('200.00'), 'Payment');

    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00')
        ->and($this->balances->pendingPayments($this->tenant->id)->toDecimalString())->toBe('200.00');
});

it('AC-LED-03 applies the payment only once it clears', function () {
    charge('tenant', '500.00', 'k:rent:tenant');
    $payment = $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('200.00'), 'Payment');

    $this->ledger->transitionStatus($payment, 'cleared');

    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('300.00')
        ->and($this->balances->pendingPayments($this->tenant->id)->toDecimalString())->toBe('0.00');
});

it('AC-LED-03 restores the balance when a cleared payment is returned', function () {
    charge('tenant', '500.00', 'k:rent:tenant');
    $payment = $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('200.00'), 'Payment');

    $this->ledger->transitionStatus($payment, 'cleared');
    $this->ledger->transitionStatus($payment, 'returned');

    // `returned` is not balance-affecting, so the money comes back onto the
    // account without anything being edited or deleted.
    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('AC-LED-04 returns an identical balance when asked twice', function () {
    charge('tenant', '500.00', 'k:rent:tenant');
    $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('123.45'), 'Payment');

    $first = $this->balances->tenantBalance($this->tenant->id);
    $second = $this->balances->tenantBalance($this->tenant->id);

    // Nothing is stored or cached, so drift has nowhere to come from (BR-03).
    expect($first->toDecimalString())->toBe($second->toDecimalString());
});

it('BR-03 computes a balance with no stored total anywhere', function () {
    charge('tenant', '500.00', 'k:rent:tenant');

    // I-1: asserted structurally in SchemaTest; asserted here as behaviour —
    // deleting nothing and touching no cache still yields the right figure.
    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('sums to the cent across many entries', function () {
    // Integer cents throughout: a float would drift over a few hundred rows.
    for ($i = 1; $i <= 100; $i++) {
        charge('tenant', '0.07', "k:cent:{$i}", 'other');
    }

    expect($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('7.00');
});

it('renders a negative balance as a credit rather than a debt', function () {
    charge('tenant', '100.00', 'k:rent:tenant');
    $payment = $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('150.00'), 'Overpayment');
    $this->ledger->transitionStatus($payment, 'cleared');

    $balance = $this->balances->tenantBalance($this->tenant->id);

    expect($balance->toDecimalString())->toBe('-50.00')
        ->and($balance->isNegative())->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | Writing
 |--------------------------------------------------------------------------
 */

it('stores charges positive and payments negative', function () {
    $c = charge('tenant', '500.00', 'k:rent:tenant');
    $p = $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('200.00'), 'Payment');

    expect($c->amount->toDecimalString())->toBe('500.00')
        ->and($p->amount->toDecimalString())->toBe('-200.00');
});

it('refuses a negative charge and a negative payment', function () {
    // The sign is the service's job, not the caller's; passing one is a bug.
    expect(fn () => $this->ledger->postCharge(
        $this->lease, 'rent', 'tenant', Money::fromString('-5.00'), 'Rent', 'k:bad',
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('-5.00'), 'Payment',
    ))->toThrow(InvalidArgumentException::class);
});

it('D-01 is idempotent on charge_key, so a catch-up run cannot double-charge', function () {
    $first = charge('tenant', '500.00', 'lease:rent:2026-09:tenant');
    $second = charge('tenant', '500.00', 'lease:rent:2026-09:tenant');

    expect($second->id)->toBe($first->id)
        ->and(LedgerEntry::count())->toBe(1)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('500.00');
});

it('denormalises tenant_id from the lease so the balance query does not join', function () {
    expect(charge('tenant', '10.00', 'k:x')->tenant_id)->toBe($this->tenant->id);
});

it('audits every write', function () {
    charge('tenant', '500.00', 'k:rent:tenant');
    $payment = $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('200.00'), 'Payment');
    $this->ledger->transitionStatus($payment, 'cleared');

    expect(DB::table('audit_logs')->where('action', 'ledger.charge.posted')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'ledger.payment.posted')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'ledger.status.changed')->count())->toBe(1);
});

/*
 |--------------------------------------------------------------------------
 | Adjustments and reversals
 |--------------------------------------------------------------------------
 */

it('AC-LED-07 rejects an adjustment with no reason', function (string $reason) {
    expect(fn () => $this->ledger->postAdjustment(
        $this->lease, 'tenant', Money::fromString('25.00'), $reason, 'Adjustment',
    ))->toThrow(InvalidArgumentException::class);

    expect(LedgerEntry::count())->toBe(0);
})->with(['', '   ']);

it('AC-LED-07 accepts an adjustment with a reason, positive or negative', function () {
    $credit = $this->ledger->postAdjustment(
        $this->lease, 'tenant', Money::fromString('-25.00'), 'Goodwill after a heating outage', 'Credit',
    );

    expect($credit->amount->toDecimalString())->toBe('-25.00')
        ->and($credit->reason)->toBe('Goodwill after a heating outage')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('-25.00');
});

it('refuses an adjustment of zero', function () {
    expect(fn () => $this->ledger->postAdjustment(
        $this->lease, 'tenant', Money::zero(), 'Nothing', 'Adjustment',
    ))->toThrow(InvalidArgumentException::class);
});

it('AC-LED-08 keeps both the original and the reversal visible', function () {
    $original = charge('tenant', '500.00', 'k:rent:tenant');

    $reversal = $this->ledger->reverse($original, 'Charged to the wrong lease');

    $entries = LedgerEntry::orderBy('id')->get();

    // Neither is hidden, flagged away or struck out of the data. What was
    // posted is a matter of record even when it was wrong.
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->id)->toBe($original->id)
        ->and($entries[1]->reverses_entry_id)->toBe($original->id)
        ->and($reversal->amount->toDecimalString())->toBe('-500.00')
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('0.00');
});

it('AC-LED-08 requires a reason to reverse', function () {
    $original = charge('tenant', '500.00', 'k:rent:tenant');

    expect(fn () => $this->ledger->reverse($original, ''))->toThrow(InvalidArgumentException::class);
    expect(LedgerEntry::count())->toBe(1);
});

it('refuses to reverse the same entry twice', function () {
    $original = charge('tenant', '500.00', 'k:rent:tenant');
    $this->ledger->reverse($original, 'First correction');

    expect(fn () => $this->ledger->reverse($original, 'Again'))->toThrow(InvalidArgumentException::class);
    expect(LedgerEntry::count())->toBe(2);
});

it('refuses to reverse a reversal', function () {
    $original = charge('tenant', '500.00', 'k:rent:tenant');
    $reversal = $this->ledger->reverse($original, 'Correction');

    expect(fn () => $this->ledger->reverse($reversal, 'Undo the undo'))
        ->toThrow(InvalidArgumentException::class);
});

/*
 |--------------------------------------------------------------------------
 | Status transitions
 |--------------------------------------------------------------------------
 */

it('I-3 permits only legal status transitions', function (string $from, string $to, bool $allowed) {
    $entry = $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('50.00'), 'Payment', status: $from,
    );

    if ($allowed) {
        expect($this->ledger->transitionStatus($entry, $to)->status)->toBe($to);
    } else {
        expect(fn () => $this->ledger->transitionStatus($entry, $to))->toThrow(InvalidArgumentException::class);
    }
})->with([
    'pending to cleared' => ['pending', 'cleared', true],
    'pending to returned' => ['pending', 'returned', true],
    'pending to void' => ['pending', 'void', true],
    'cleared to returned' => ['cleared', 'returned', true],
    // Terminal: something already undone cannot be undone again.
    'returned to cleared' => ['returned', 'cleared', false],
    'void to cleared' => ['void', 'cleared', false],
]);

it('treats a transition to the same status as a no-op', function () {
    $entry = charge('tenant', '10.00', 'k:x');

    expect($this->ledger->transitionStatus($entry, 'posted')->status)->toBe('posted')
        ->and(DB::table('audit_logs')->where('action', 'ledger.status.changed')->count())->toBe(0);
});

/*
 |--------------------------------------------------------------------------
 | Outstanding charges — what WP-11's waterfall will consume
 |--------------------------------------------------------------------------
 */

it('lists outstanding charges oldest first', function () {
    $this->ledger->postCharge($this->lease, 'late_fee', 'tenant', Money::fromString('50.00'), 'Late fee', 'k:fee',
        postedOn: Carbon\CarbonImmutable::parse('2026-01-06'));
    $this->ledger->postCharge($this->lease, 'rent', 'tenant', Money::fromString('500.00'), 'Rent', 'k:feb',
        postedOn: Carbon\CarbonImmutable::parse('2026-02-01'));

    $outstanding = $this->balances->outstandingCharges($this->tenant->id);

    // AC-LED-05's setup. The allocation itself is WP-11.
    expect($outstanding)->toHaveCount(2)
        ->and($outstanding[0]->outstanding->toDecimalString())->toBe('50.00')
        ->and($outstanding[1]->outstanding->toDecimalString())->toBe('500.00');
});

it('D-02 counts a reversed allocation as unpaid again', function () {
    $rent = charge('tenant', '500.00', 'k:rent');
    $payment = $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('500.00'), 'Payment');

    $paymentRow = DB::table('payments')->insertGetId([
        'lease_id' => $this->lease->id, 'tenant_id' => $this->tenant->id,
        'payer' => 'tenant', 'amount' => '500.00', 'method' => 'cheque',
        'gateway' => 'manual', 'idempotency_key' => (string) Str::uuid(),
        'status' => 'settled', 'submitted_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('payment_allocations')->insert([
        'payment_id' => $paymentRow, 'charge_entry_id' => $rent->id,
        'amount' => '500.00', 'created_at' => now(),
    ]);

    expect($this->balances->outstandingCharges($this->tenant->id))->toHaveCount(0);

    // The payment bounces. Without reversed_at the allocation would linger and
    // the charge would still look paid — silently, and only for this tenant.
    DB::table('payment_allocations')->where('payment_id', $paymentRow)->update(['reversed_at' => now()]);

    expect($this->balances->outstandingCharges($this->tenant->id))->toHaveCount(1);
});

it('shows a running balance that skips pending entries but still displays them', function () {
    charge('tenant', '500.00', 'k:rent');
    $this->ledger->postPayment($this->lease, 'tenant', Money::fromString('200.00'), 'Payment');

    $rows = $this->balances->runningBalance($this->tenant->id);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->running->toDecimalString())->toBe('500.00')
        // Shown, so the tenant sees it is being processed; not counted, so the
        // balance does not pretend it cleared.
        ->and($rows[1]->counts)->toBeFalse()
        ->and($rows[1]->running->toDecimalString())->toBe('500.00');
});
