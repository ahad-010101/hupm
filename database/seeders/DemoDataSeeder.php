<?php

namespace Database\Seeders;

use App\Support\Money;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Synthetic portfolio for local development.  [WP-01D, DERIVED]
 *
 * Not in documents 01–05. It exists because the only data path the specs
 * define is WP-08's Rent Manager import, which needs a file we have not been
 * given and a host we have not validated — so without this, every screen from
 * Slice 1 onward renders empty.
 *
 * Built to the real portfolio shape from the PRD (25 properties, 27 units,
 * 26 tenants, predominantly Section 8) rather than a handful of rows, so list
 * density, pagination and the admin dashboard are exercised at true scale.
 *
 * Deterministic: a fixed seed means a bug found on one machine reproduces on
 * another, and screenshots stay comparable between runs.
 *
 * No ledger rows are written here. Once WP-09 lands, history is generated
 * through LedgerService — invariant I-2 applies to seeders as much as to
 * controllers, and a seeder that inserts ledger rows directly is how the sole
 * writer rule quietly dies.
 */
class DemoDataSeeder extends Seeder
{
    private const SEED = 20260813;

    /** 24 single-unit properties plus one triplex = 25 properties, 27 units. */
    private const SINGLE_UNIT_PROPERTIES = 24;

    private const MULTI_UNIT_COUNT = 3;

    private const TENANT_COUNT = 26;

    private Generator $faker;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            // Synthetic tenant records on a production box are a privacy
            // incident waiting to be mistaken for real ones.
            throw new RuntimeException('DemoDataSeeder must never run outside local or testing.');
        }

        $this->faker = FakerFactory::create('en_US');
        $this->faker->seed(self::SEED);
        mt_srand(self::SEED);

        $authorityId = $this->housingAuthority();
        $unitIds = $this->properties();
        $tenantIds = $this->tenants();
        $this->leases($unitIds, $tenantIds, $authorityId);

        $this->command?->info(sprintf(
            'Demo data: %d properties, %d units, %d tenants.',
            DB::table('properties')->count(),
            DB::table('units')->count(),
            DB::table('tenants')->count(),
        ));
    }

    private function housingAuthority(): int
    {
        $existing = DB::table('housing_authorities')->value('id');

        if ($existing) {
            return $existing;
        }

        return DB::table('housing_authorities')->insertGetId([
            'name' => 'Georgia DCA — Housing Choice Voucher (placeholder)',
            'remittance_type' => 'per_tenant', // [GATE Q-2]
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<int> unit ids, in creation order */
    private function properties(): array
    {
        // Real Georgia cities with their actual ZIPs — ZIP drives weather-alert
        // targeting (WP-21), so a plausible one makes that feature testable.
        $places = [
            ['Atlanta', '30310', 'Fulton'],
            ['Atlanta', '30318', 'Fulton'],
            ['Decatur', '30030', 'DeKalb'],
            ['East Point', '30344', 'Fulton'],
            ['College Park', '30337', 'Fulton'],
            ['Marietta', '30060', 'Cobb'],
            ['Smyrna', '30080', 'Cobb'],
            ['Riverdale', '30274', 'Clayton'],
            ['Forest Park', '30297', 'Clayton'],
            ['Stone Mountain', '30083', 'DeKalb'],
        ];

        $unitIds = [];

        for ($i = 0; $i < self::SINGLE_UNIT_PROPERTIES + 1; $i++) {
            [$city, $zip, $county] = $places[$i % count($places)];
            $isMulti = $i === self::SINGLE_UNIT_PROPERTIES; // the last one is the triplex

            $propertyId = DB::table('properties')->insertGetId([
                'name' => $isMulti
                    ? $this->faker->lastName().' Court'
                    : $this->faker->buildingNumber().' '.$this->faker->streetName(),
                'street_address' => $this->faker->buildingNumber().' '.$this->faker->streetName(),
                'city' => $city,
                'state' => 'GA',
                'zip' => $zip,
                'county' => $county,
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $unitsHere = $isMulti ? self::MULTI_UNIT_COUNT : 1;

            for ($u = 0; $u < $unitsHere; $u++) {
                $unitIds[] = DB::table('units')->insertGetId([
                    'property_id' => $propertyId,
                    'unit_number' => $isMulti ? chr(65 + $u) : '1',
                    'bedrooms' => $this->faker->numberBetween(1, 4),
                    'bathrooms' => $this->faker->randomElement(['1.0', '1.5', '2.0']),
                    'status' => 'vacant', // corrected below when a lease is attached
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $unitIds;
    }

    /** @return list<int> tenant ids */
    private function tenants(): array
    {
        $ids = [];

        for ($i = 0; $i < self::TENANT_COUNT; $i++) {
            $first = $this->faker->firstName();
            $last = $this->faker->lastName();

            // Two tenants have no email address. Q-4 is still unanswered, and
            // the no-login path has to be visible from the first screen rather
            // than discovered at go-live: these tenants get no user account,
            // and their notifications resolve to not_deliverable (AC-NTF-03).
            $hasEmail = $i >= 2;
            $email = $hasEmail ? sprintf('%s.%s%d@example.test', strtolower($first), strtolower($last), $i) : null;

            $tenantId = DB::table('tenants')->insertGetId([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => $this->faker->numerify('(404) ###-####'),
                'emergency_contact_name' => $this->faker->name(),
                'emergency_contact_phone' => $this->faker->numerify('(770) ###-####'),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($hasEmail) {
                DB::table('users')->insert([
                    'tenant_id' => $tenantId,
                    'name' => "{$first} {$last}",
                    'email' => $email,
                    // Every demo tenant shares this password. It satisfies the 12-character
                    // policy so the seed data cannot teach a habit the app rejects. The seeder refuses
                    // to run outside local/testing, so it never reaches a real box.
                    'password' => Hash::make('hupm-local-dev-2026'),
                    'role' => 'tenant',
                    // A few are still `invited` so the set-password flow (WP-04)
                    // has something to exercise.
                    'status' => $i % 9 === 0 ? 'invited' : 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[] = $tenantId;
        }

        return $ids;
    }

    /**
     * 24 active leases, 3 units left vacant, one ended lease on a turned-over
     * unit, and one tenant with no lease at all.
     *
     * @param  list<int>  $unitIds
     * @param  list<int>  $tenantIds
     */
    private function leases(array $unitIds, array $tenantIds, int $authorityId): void
    {
        $activeLeases = 24;

        for ($i = 0; $i < $activeLeases; $i++) {
            // Predominantly Section 8, matching the real portfolio.
            $subsidised = $i < 20;
            $this->lease($unitIds[$i], $tenantIds[$i], $subsidised ? $authorityId : null, 'active', $subsidised);

            DB::table('units')->where('id', $unitIds[$i])->update(['status' => 'occupied']);
        }

        // Turnover: a previous tenancy on unit 0, now ended. D-03 permits this
        // alongside the active lease because active_unit_key is NULL when the
        // status is not 'active' — worth having in the data so the constraint is
        // exercised by real navigation, not only by a test.
        $formerTenantId = $tenantIds[$activeLeases];
        $this->lease($unitIds[0], $formerTenantId, $authorityId, 'ended', true, yearsAgo: 2);
        DB::table('tenants')->where('id', $formerTenantId)->update(['status' => 'former']);

        // tenantIds[25] deliberately has no lease — a tenant record created
        // before their lease is signed.
    }

    private function lease(
        int $unitId,
        int $tenantId,
        ?int $authorityId,
        string $status,
        bool $subsidised,
        int $yearsAgo = 0,
    ): void {
        $total = Money::fromMinor(mt_rand(85_000, 175_000)); // $850–$1,750

        // On a subsidised lease the tenant pays roughly a fifth; allocate()
        // guarantees the two portions sum to the total exactly, which is what
        // FR-REG-02 requires (AC-REG-03).
        [$tenantPortion, $haPortion] = $subsidised
            ? $total->allocate([2, 8])
            : [$total, Money::zero()];

        $start = now()->subYears($yearsAgo)->subMonths(6)->startOfMonth();

        DB::table('leases')->insert([
            'unit_id' => $unitId,
            'tenant_id' => $tenantId,
            'housing_authority_id' => $authorityId,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addYear()->subDay()->toDateString(),
            'total_contract_rent' => $total->toDecimalString(),
            'tenant_portion' => $tenantPortion->toDecimalString(),
            'ha_portion' => $haPortion->toDecimalString(),
            'rent_due_day' => 1,
            'grace_period_days' => 5,
            'late_fee_flat' => '50.00',
            'late_fee_daily' => '5.00',
            'late_fee_max' => '150.00',
            'returned_payment_fee' => '35.00',
            'security_deposit' => $tenantPortion->toDecimalString(),
            'is_subsidised' => $subsidised,
            'hap_contract_number' => $subsidised ? 'HAP-'.mt_rand(100000, 999999) : null,
            'utility_responsibility' => 'Tenant pays electricity and gas; owner pays water.',
            'partial_payment_policy' => 'full_only',
            'partial_requires_approval' => true,
            'ledger_review_required' => false,
            'delinquency_state' => 'current',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
