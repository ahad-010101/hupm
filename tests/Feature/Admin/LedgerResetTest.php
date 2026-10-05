<?php

use App\Domain\Ledger\LedgerService;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Emptying the ledger before go-live  [WP-50]
|--------------------------------------------------------------------------
|
| The portfolio is real; the money on it was test data. Both sides of the
| ledger go, because a balance is SUM(amount) at read time (I-1) and there is
| no other way to make one zero.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger = app(LedgerService::class);
    $this->tenant = Tenant::factory()->create();

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00',
        'tenant_portion' => '500.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
        // The two that decide whether a resident can pay at all.
        'delinquency_state' => 'management_review',
        'ledger_reviewed_period' => '2026-02',
    ])->save();

    $this->charge = $this->ledger->postCharge(
        $this->lease, 'rent', 'tenant', Money::fromString('500.00'),
        'Rent — February 2026', 'reset:rent', CarbonImmutable::parse('2026-02-01'), '2026-02',
    );

    $payment = Payment::factory()->create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'amount' => '200.00',
        'method' => 'echeck',
        'status' => Payment::STATUS_SETTLED,
        'idempotency_key' => (string) Str::uuid(),
        'submitted_at' => now()->subDay(),
    ]);

    $this->ledger->postPayment(
        $this->lease, 'tenant', Money::fromString('200.00'),
        'Payment submitted online', $payment->id, 'cleared',
    );

    // Written straight to the table: the model is unfillable by design, so
    // that nothing outside AllocationService can write one (I-2).
    DB::table('payment_allocations')->insert([
        'payment_id' => $payment->id,
        'charge_entry_id' => $this->charge->id,
        'amount' => '200.00',
        'created_at' => now(),
    ]);
});

it('AC-LED-20 clears entries that reverse other entries', function () {
    // The bug production found and the fixtures missed.
    // `ledger_entries.reverses_entry_id` is a SELF-referencing foreign key —
    // a correction is a reversing entry, never an edit (I-3) — so a bulk
    // delete fails on the table's own constraint. Every row is both a parent
    // and a child. There were 129 of these on the live system.
    $this->ledger->reverse($this->charge, 'Charged in error');

    expect(LedgerEntry::whereNotNull('reverses_entry_id')->count())->toBe(1);

    $this->artisan('hupm:ledger-reset --force')->assertSuccessful();

    expect(LedgerEntry::count())->toBe(0)
        ->and(Lease::count())->toBe(1);
});

it('AC-LED-20 empties the ledger and leaves the portfolio standing', function () {
    expect(LedgerEntry::count())->toBe(2);

    $this->artisan('hupm:ledger-reset --force')->assertSuccessful();

    expect(LedgerEntry::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0)
        // The portfolio is the real data and must survive untouched.
        ->and(Tenant::count())->toBe(1)
        ->and(Lease::count())->toBe(1)
        ->and(Unit::count())->toBe(1)
        // What the lease is worth is configuration, not money.
        ->and($this->lease->fresh()->tenant_portion->toDecimalString())->toBe('500.00');
});

it('AC-LED-20 lets a resident pay again afterwards', function () {
    $this->artisan('hupm:ledger-reset --force')->assertSuccessful();

    // A zero balance that still refuses an online payment is the worst
    // outcome available here, and it is invisible until somebody tries.
    expect($this->lease->fresh()->delinquency_state)->toBe('current')
        ->and($this->lease->fresh()->ledger_reviewed_period)->toBeNull();
});

it('AC-LED-20 records the wipe in a log it does not then delete', function () {
    $this->artisan('hupm:ledger-reset --force')->assertSuccessful();

    $row = DB::table('audit_logs')->where('action', 'ledger.reset')->sole();
    $changes = json_decode($row->changes, true);

    // A wipe that left no trace would be indistinguishable from data loss.
    expect($changes['deleted']['ledger_entries'])->toBe(2)
        ->and($changes['deleted']['payments'])->toBe(1)
        ->and($changes['leases_returned_to_current'])->toBe(1);
});

it('AC-LED-20 changes nothing on a dry run', function () {
    $this->artisan('hupm:ledger-reset --dry-run')->assertSuccessful();

    expect(LedgerEntry::count())->toBe(2)
        ->and(Payment::count())->toBe(1)
        ->and($this->lease->fresh()->delinquency_state)->toBe('management_review')
        ->and(DB::table('audit_logs')->where('action', 'ledger.reset')->count())->toBe(0);
});

it('AC-LED-20 stops unless the operator types the word', function () {
    $this->artisan('hupm:ledger-reset')
        ->expectsQuestion('Type the word ERASE to continue', 'yes')
        ->assertFailed();

    expect(LedgerEntry::count())->toBe(2)
        ->and(Payment::count())->toBe(1);
});
