<?php

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

it('writes no ledger rows, because LedgerService does not exist yet', function () {
    // I-2 applies to seeders. When WP-09 lands, history is generated through
    // LedgerService — a seeder inserting ledger rows directly is how the sole
    // writer rule quietly dies.
    expect(DB::table('ledger_entries')->count())->toBe(0);
});
