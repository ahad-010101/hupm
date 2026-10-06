<?php

use App\Domain\Charges\ChargePostingService;
use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Ledger\LedgerService;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The opening period  [WP-51]
|--------------------------------------------------------------------------
|
| "What is due" counts from the lease start date, which is right — and is why
| the first run against the real portfolio posted 3,474 months of rent reaching
| back to 2007. The tenancies genuinely run that far; this system was not
| keeping the books for any of it.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger = app(LedgerService::class);
    $this->balances = app(BalanceCalculator::class);
    $this->charges = app(ChargePostingService::class);
    $this->tenant = Tenant::factory()->create();

    // A long tenancy, as the real ones are.
    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2007-08-01',
        'end_date' => '2027-12-31',
        'total_contract_rent' => '725.00',
        'tenant_portion' => '725.00',
        'ha_portion' => '0.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();
});

it('AC-CHG-14 posts nineteen years of rent when no opening month is set', function () {
    // Not a bug — the honest answer to "what is due" on a 2007 lease. It is
    // the reason the setting exists, and this test is what makes the change
    // in behaviour visible rather than implied.
    $this->charges->postFor($this->lease, CarbonImmutable::parse('2026-10-06'));

    expect(LedgerEntry::where('category', 'rent')->count())->toBeGreaterThan(200)
        ->and(LedgerEntry::where('period', '2007-08')->exists())->toBeTrue();
});

it('AC-CHG-14 posts only from the opening month once one is set', function () {
    app(Settings::class)->set('charges.post_from_period', '2026-10');

    $this->charges->postFor($this->lease, CarbonImmutable::parse('2026-10-06'));

    expect(LedgerEntry::where('category', 'rent')->count())->toBe(1)
        ->and(LedgerEntry::where('period', '2026-10')->exists())->toBeTrue()
        ->and(LedgerEntry::where('period', '2007-08')->exists())->toBeFalse()
        // The whole point: what the resident owes is this month, not a life.
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('725.00');
});

it('AC-CHG-14 leaves the lease start date alone', function () {
    app(Settings::class)->set('charges.post_from_period', '2026-10');
    $this->charges->postFor($this->lease, CarbonImmutable::parse('2026-10-06'));

    // A start date is a fact about a tenancy. Rewriting it to tidy a report
    // would be the easy fix and a false record.
    expect($this->lease->fresh()->start_date->toDateString())->toBe('2007-08-01');
});

it('AC-CHG-14 refuses a malformed opening month rather than ignoring it', function () {
    app(Settings::class)->set('charges.post_from_period', 'October');

    // Treating a typo as "no floor" would quietly back-post 2007 onwards on
    // the next nightly run — the exact outcome the setting prevents.
    expect(fn () => $this->charges->postFor($this->lease, CarbonImmutable::parse('2026-10-06')))
        ->toThrow(RuntimeException::class);

    expect(LedgerEntry::count())->toBe(0);
});

it('AC-CHG-14 trims the ledger and moves the boundary together', function () {
    $this->charges->postFor($this->lease, CarbonImmutable::parse('2026-10-06'));
    $before = LedgerEntry::count();

    $this->artisan('hupm:ledger-open-from 2026-10 --force')->assertSuccessful();

    expect(LedgerEntry::count())->toBe(1)
        ->and(LedgerEntry::sole()->period)->toBe('2026-10')
        ->and($before)->toBeGreaterThan(200)
        // The half that is easy to forget: without this the nightly run
        // rebuilds everything by morning.
        ->and(app(Settings::class)->string('charges.post_from_period'))->toBe('2026-10')
        ->and(DB::table('audit_logs')->where('action', 'ledger.opening_period.set')->count())->toBe(1);
});

it('AC-CHG-14 stays trimmed when the nightly run fires again', function () {
    $this->charges->postFor($this->lease, CarbonImmutable::parse('2026-10-06'));
    $this->artisan('hupm:ledger-open-from 2026-10 --force')->assertSuccessful();

    // Tomorrow morning. This is the assertion the whole package turns on.
    $this->charges->postFor($this->lease->fresh(), CarbonImmutable::parse('2026-10-07'));

    expect(LedgerEntry::where('category', 'rent')->count())->toBe(1)
        ->and($this->balances->tenantBalance($this->tenant->id)->toDecimalString())->toBe('725.00');
});

it('AC-CHG-14 refuses a month that has not started', function () {
    $this->artisan('hupm:ledger-open-from 2099-01 --force')->assertFailed();
});
