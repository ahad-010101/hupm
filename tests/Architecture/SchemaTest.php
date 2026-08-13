<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Schema guarantees  [WP-01 DoD]
|--------------------------------------------------------------------------
|
| Structural invariants asserted against the live schema, plus behavioural
| proof that the D-01 and D-03 constraints actually fire. A constraint that
| exists in information_schema but does not reject a bad write is worthless,
| so both are exercised with real inserts.
|
*/

uses(RefreshDatabase::class);

/** @return list<string> every column name in the database, as table.column */
function allColumns(): array
{
    return DB::table('information_schema.columns')
        ->where('table_schema', DB::getDatabaseName())
        ->selectRaw('CONCAT(table_name, ".", column_name) as ref')
        ->pluck('ref')
        ->all();
}

it('I-1 has no balance column in any table', function () {
    $offenders = array_filter(allColumns(), fn ($c) => str_ends_with(strtolower($c), '.balance'));

    // A stored balance is a second source of truth that will drift from the
    // ledger. Balances are SUM(amount) at read time, always (BR-03).
    expect(array_values($offenders))->toBe([]);
});

it('I-5 stores no bank account, routing or card number anywhere', function () {
    $forbidden = ['account_number', 'routing', 'card_number', 'cvv', 'iban', 'sort_code'];

    $offenders = array_values(array_filter(
        allColumns(),
        function ($column) use ($forbidden) {
            $name = strtolower($column);
            foreach ($forbidden as $needle) {
                // gateway_customer_profile_id and similar are opaque tokens, not
                // instrument data, so match on the forbidden fragments only.
                if (str_contains($name, $needle)) {
                    return true;
                }
            }

            return false;
        }
    ));

    expect($offenders)->toBe([]);
});

it('creates every table named in the entity inventory', function () {
    $expected = [
        'users', 'properties', 'units', 'housing_authorities', 'tenants', 'leases',
        'lease_charge_schedules', 'ledger_entries', 'payments', 'payment_allocations',
        'payment_profiles', 'recurring_payments', 'delinquency_events', 'payment_arrangements',
        'vendors', 'maintenance_requests', 'maintenance_events', 'maintenance_attachments',
        'documents', 'signature_requests', 'signature_events', 'consent_records',
        'notices', 'notice_recipients', 'notification_logs', 'weather_alerts',
        'audit_logs', 'job_runs', 'settings', 'imports',
        'counters', // [DERIVED] D-09
    ];

    $missing = array_values(array_filter($expected, fn ($t) => ! Schema::hasTable($t)));

    expect($missing)->toBe([]);
});

it('D-01 rejects a duplicate charge_key but allows many null ones', function () {
    [$leaseId, $tenantId] = seedLease();

    $entry = fn (array $overrides = []) => array_merge([
        'lease_id' => $leaseId,
        'tenant_id' => $tenantId,
        'type' => 'charge',
        'category' => 'rent',
        'payer' => 'tenant',
        'amount' => 300.00,
        'status' => 'posted',
        'posted_on' => '2026-09-01',
        'description' => 'Rent',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('ledger_entries')->insert($entry(['charge_key' => "{$leaseId}:rent:2026-09:tenant"]));

    // Re-running the charge job for the same period must not double-charge.
    expect(fn () => DB::table('ledger_entries')->insert($entry(['charge_key' => "{$leaseId}:rent:2026-09:tenant"])))
        ->toThrow(QueryException::class);

    // Payments and adjustments carry no charge_key. MySQL permits unlimited
    // NULLs in a unique index, so these must not collide with each other.
    DB::table('ledger_entries')->insert($entry(['type' => 'payment', 'amount' => -100, 'charge_key' => null]));
    DB::table('ledger_entries')->insert($entry(['type' => 'payment', 'amount' => -200, 'charge_key' => null]));

    expect(DB::table('ledger_entries')->whereNull('charge_key')->count())->toBe(2);
});

it('D-01 allows a daily late fee for every day of the same month', function () {
    // This is the case the spec's original index made impossible: it permitted
    // only one late_fee row per (lease, period, category, payer), which directly
    // contradicts FR-FEE-01's daily accrual.
    [$leaseId, $tenantId] = seedLease();

    foreach (['2026-09-06', '2026-09-07', '2026-09-08'] as $day) {
        DB::table('ledger_entries')->insert([
            'lease_id' => $leaseId,
            'tenant_id' => $tenantId,
            'type' => 'charge',
            'category' => 'late_fee',
            'payer' => 'tenant',
            'amount' => 5.00,
            'status' => 'posted',
            'posted_on' => $day,
            'period' => '2026-09',
            'description' => 'Daily late fee',
            'charge_key' => "{$leaseId}:latefee_daily:{$day}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('ledger_entries')->where('category', 'late_fee')->count())->toBe(3);
});

it('D-03 permits only one active lease per unit', function () {
    [, $tenantId, $unitId] = seedLease();

    // AC-REG-04. Two admins activating a lease on the same unit at the same
    // moment both pass an application-level check; the database must refuse.
    expect(fn () => DB::table('leases')->insert([
        'unit_id' => $unitId,
        'tenant_id' => $tenantId,
        'start_date' => '2027-01-01',
        'end_date' => '2027-12-31',
        'total_contract_rent' => 900.00,
        'tenant_portion' => 300.00,
        'ha_portion' => 600.00,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('D-03 still allows an ended lease alongside an active one on the same unit', function () {
    [, $tenantId, $unitId] = seedLease();

    // Turnover must remain possible — the constraint is on active leases only.
    DB::table('leases')->insert([
        'unit_id' => $unitId,
        'tenant_id' => $tenantId,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'total_contract_rent' => 850.00,
        'tenant_portion' => 250.00,
        'ha_portion' => 600.00,
        'status' => 'ended',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('leases')->where('unit_id', $unitId)->count())->toBe(2);
});

it('D-02 keeps a reversed allocation on the record rather than deleting it', function () {
    expect(Schema::hasColumn('payment_allocations', 'reversed_at'))->toBeTrue();
});

/**
 * Minimal chain: property → unit → tenant → active lease.
 *
 * @return array{0:int,1:int,2:int} lease id, tenant id, unit id
 */
function seedLease(): array
{
    $propertyId = DB::table('properties')->insertGetId([
        'name' => 'Test Property',
        'street_address' => '1 Test St',
        'city' => 'Atlanta',
        'state' => 'GA',
        'zip' => '30301',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unitId = DB::table('units')->insertGetId([
        'property_id' => $propertyId,
        'unit_number' => 'A1',
        'status' => 'occupied',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenantId = DB::table('tenants')->insertGetId([
        'first_name' => 'Test',
        'last_name' => 'Tenant',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $leaseId = DB::table('leases')->insertGetId([
        'unit_id' => $unitId,
        'tenant_id' => $tenantId,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => 900.00,
        'tenant_portion' => 300.00,
        'ha_portion' => 600.00,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$leaseId, $tenantId, $unitId];
}
