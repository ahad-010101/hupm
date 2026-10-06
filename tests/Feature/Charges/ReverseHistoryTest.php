<?php

use App\Domain\Charges\ChargePostingService;
use App\Domain\Ledger\BalanceCalculator;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Reversing the history instead of deleting it  [WP-52]
|--------------------------------------------------------------------------
|
| The old rent was really charged; it was also settled outside this system.
| Deleting it makes the balance right and the record false — nobody can then
| ask what was charged in 2019 or why it stopped counting. I-3 already has the
| answer: corrections are reversing entries.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->balances = app(BalanceCalculator::class);
    $this->charges = app(ChargePostingService::class);
    $this->tenant = Tenant::factory()->create();

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2024-01-01',
        'end_date' => '2027-12-31',
        'total_contract_rent' => '725.00',
        'tenant_portion' => '725.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();

    $this->charges->postFor($this->lease, CarbonImmutable::parse('2026-10-06'));
});

it('AC-LED-21 nets the old months to zero while keeping every row', function () {
    $before = LedgerEntry::count();

    expect($before)->toBeGreaterThan(30);

    $this->artisan('hupm:ledger-reverse-before 2026-10 --force')->assertSuccessful();

    // Nothing is gone — the record of what was charged survives in full.
    expect(LedgerEntry::where('type', '!=', 'reversal')->count())->toBe($before)
        ->and(LedgerEntry::where('type', 'reversal')->count())->toBe($before - 1)
        // ...and what the resident owes is this month alone.
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('725.00');
});

it('AC-LED-21 dates each reversal with the entry it reverses', function () {
    $this->artisan('hupm:ledger-reverse-before 2026-10 --force')->assertSuccessful();

    $original = LedgerEntry::where('period', '2024-01')->where('type', 'charge')->sole();
    $reversal = LedgerEntry::where('reverses_entry_id', $original->id)->sole();

    // Each historical period nets to zero on its own, so the opening month
    // stands alone in the reports rather than carrying thousands of
    // offsetting lines dated today.
    expect($reversal->posted_on->toDateString())->toBe($original->posted_on->toDateString())
        ->and($reversal->period)->toBe('2024-01')
        ->and($reversal->payer)->toBe($original->payer)
        ->and($reversal->amount->toDecimalString())->toBe('-725.00')
        ->and($reversal->reason)->toContain('Settled outside this system');
});

it('AC-LED-21 leaves the opening month untouched', function () {
    $this->artisan('hupm:ledger-reverse-before 2026-10 --force')->assertSuccessful();

    expect(LedgerEntry::where('period', '2026-10')->where('type', 'reversal')->count())->toBe(0)
        ->and(LedgerEntry::where('period', '2026-10')->where('type', 'charge')->count())->toBe(1);
});

it('AC-LED-21 does nothing on a second run', function () {
    $this->artisan('hupm:ledger-reverse-before 2026-10 --force')->assertSuccessful();
    $after = LedgerEntry::count();

    // Idempotent (I-8): reverse() refuses an entry that already has one, so a
    // repeat run cannot double-credit anybody.
    $this->artisan('hupm:ledger-reverse-before 2026-10 --force')->assertSuccessful();

    expect(LedgerEntry::count())->toBe($after)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('725.00');
});

it('AC-LED-21 changes nothing on a dry run', function () {
    $before = LedgerEntry::count();

    $this->artisan('hupm:ledger-reverse-before 2026-10 --dry-run')->assertSuccessful();

    expect(LedgerEntry::count())->toBe($before)
        ->and(DB::table('audit_logs')->where('action', 'ledger.reversed_before')->count())->toBe(0);
});
