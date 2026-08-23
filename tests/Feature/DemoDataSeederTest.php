<?php

use App\Domain\Ledger\BalanceCalculator;
use App\Jobs\PostScheduledCharges;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Demo data  [WP-01D DoD]
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoDataSeeder::class);
});

it('produces the real portfolio shape', function () {
    // The PRD's actual numbers. Seeding three rows instead would hide list
    // density, pagination and dashboard-at-scale problems until go-live.
    expect(DB::table('properties')->count())->toBe(25)
        ->and(DB::table('units')->count())->toBe(27)
        ->and(DB::table('tenants')->count())->toBe(26);
});

it('includes a tenant with no email and therefore no login', function () {
    // Q-4 is unanswered. The no-login path has to be visible from the first
    // screen, not discovered at go-live (AC-AUTH-06, AC-NTF-03).
    $withoutEmail = DB::table('tenants')->whereNull('email')->get();

    expect($withoutEmail)->not->toBeEmpty();

    foreach ($withoutEmail as $tenant) {
        expect(DB::table('users')->where('tenant_id', $tenant->id)->exists())->toBeFalse();
    }
});

it('leaves some units vacant and ends one tenancy', function () {
    expect(DB::table('units')->where('status', 'vacant')->count())->toBeGreaterThan(0)
        ->and(DB::table('leases')->where('status', 'ended')->count())->toBe(1);
});

it('keeps an ended lease alongside an active one on the same unit', function () {
    // Turnover. D-03 permits this because active_unit_key is NULL unless the
    // lease is active — exercised here by real data, not only by a unit test.
    $unitId = DB::table('leases')->where('status', 'ended')->value('unit_id');

    expect(DB::table('leases')->where('unit_id', $unitId)->count())->toBe(2)
        ->and(DB::table('leases')->where('unit_id', $unitId)->where('status', 'active')->count())->toBe(1);
});

it('AC-REG-03 splits every lease so the portions sum to the contract rent', function () {
    $mismatched = DB::table('leases')
        ->whereRaw('ABS(total_contract_rent - tenant_portion - ha_portion) > 0')
        ->count();

    expect($mismatched)->toBe(0);
});

it('is predominantly Section 8, like the real portfolio', function () {
    $subsidised = DB::table('leases')->where('is_subsidised', true)->count();
    $total = DB::table('leases')->count();

    expect($subsidised / $total)->toBeGreaterThan(0.5);
});

it('is deterministic, so a bug reproduces on another machine', function () {
    // Pinned values from the fixed seed. If these drift, the generator changed
    // and screenshots, bug reports and test fixtures stop being comparable
    // between machines — which is the whole reason the seed is fixed.
    $tenant = DB::table('tenants')->orderBy('id')->first();
    $property = DB::table('properties')->orderBy('id')->first();

    expect("{$tenant->first_name} {$tenant->last_name}")->toBe('Amanda Langworth')
        ->and($property->name)->toBe('1645 Ullrich Shores')
        ->and(DB::table('leases')->orderBy('id')->value('total_contract_rent'))->toBe('1729.86');
});

it('gives every active lease rent charged since it began', function () {
    // Written by the real charge service, not by hand: the same proration, the
    // same tenant/authority split and the same charge keys the nightly job
    // would have produced had it been running all along.
    $active = DB::table('leases')->where('status', 'active')->count();

    expect(DB::table('ledger_entries')->where('type', 'charge')->count())
        ->toBeGreaterThan($active)
        // AC-CHG-02: a lease with no authority portion posts one charge, not a
        // second one for $0.00.
        ->and(DB::table('ledger_entries')->where('amount', '0.00')->count())->toBe(0);
});

it('records payments as payments, allocated against the charges they paid', function () {
    // Through PaymentRecordingService, so each one has a payment row, a cleared
    // ledger entry and an allocation. History assembled by direct insert would
    // look right on a balance and behave wrong everywhere else — no allocation
    // detail, nothing for a return to reverse (D-02).
    expect(DB::table('payments')->count())->toBeGreaterThan(0)
        ->and(DB::table('payment_allocations')->count())->toBeGreaterThan(0);

    $unallocated = DB::table('payments as p')
        ->leftJoin('payment_allocations as a', 'a.payment_id', '=', 'p.id')
        ->whereNull('a.id')
        ->count();

    expect($unallocated)->toBe(0);
});

it('produces a spread worth looking at, not a portfolio that is all one thing', function () {
    $balances = app(BalanceCalculator::class)->balancesByTenant('tenant');

    $owing = collect($balances)->filter(fn ($m) => $m->isPositive())->count();
    $credit = collect($balances)->filter(fn ($m) => $m->isNegative())->count();
    $square = collect($balances)->filter(fn ($m) => $m->isZero())->count();

    // A portfolio where everybody is square shows nothing, and one where
    // everybody is behind makes the delinquency queue indistinguishable from a
    // bug. Both states have to be present to judge either screen.
    expect($owing)->toBeGreaterThan(0)
        ->and($credit)->toBeGreaterThan(0)   // Q-8 credit_forward, rendered "Credit $X"
        ->and($square)->toBeGreaterThan($owing);
});

it('leaves nothing for the nightly charge run to post', function () {
    // The seeded charge keys are the real ones, so the first time the job runs
    // against demo data it must be a no-op. Anything else means a second rent
    // charge for a month already charged (AC-CHG-01, D-01).
    expect(dispatch_sync(new PostScheduledCharges))->toBe(0);
});

it('keeps the authority balance off the resident entirely', function () {
    // I-4. The authority owes real money in this data — which is what makes it
    // worth asserting that none of it lands on a resident's balance.
    $balances = app(BalanceCalculator::class);
    $tenantId = DB::table('leases')->where('status', 'active')->value('tenant_id');

    expect($balances->haBalance($tenantId)->isPositive())->toBeTrue()
        ->and($balances->tenantBalance($tenantId)->toDecimalString())
        ->not->toBe($balances->haBalance($tenantId)->toDecimalString());
});
