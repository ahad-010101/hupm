<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A minimal slice of the world reference data, for tests.  [D-19]
 *
 * The real WorldSeeder inserts 250 countries, 5,000 states and 150,000 cities
 * and takes about half a minute. RefreshDatabase wipes the database between
 * tests, so running it for real would add that cost to every single test —
 * hundreds of times over.
 *
 * This inserts the handful of rows the address validation actually needs, and
 * deliberately includes two countries so the cross-country checks have
 * something to fail against: "Ontario" must not be accepted under the United
 * States, and "Toronto" must not be accepted under Georgia.
 */
class TestAddressSeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['id' => 1, 'iso2' => 'US', 'iso3' => 'USA', 'name' => 'United States'],
            ['id' => 2, 'iso2' => 'CA', 'iso3' => 'CAN', 'name' => 'Canada'],
        ];

        foreach ($countries as $country) {
            DB::table('countries')->updateOrInsert(['id' => $country['id']], [
                'name' => $country['name'],
                'iso2' => $country['iso2'],
                'iso3' => $country['iso3'],
                // Required by the package's schema and unused by us; filled so
                // the insert succeeds rather than made nullable, because
                // altering a dependency's table is a worse trade.
                'phone_code' => '1',
                'region' => 'Americas',
                'subregion' => 'Northern America',
                'status' => 1,
            ]);
        }

        $states = [
            ['id' => 1, 'country_id' => 1, 'name' => 'Georgia', 'country_code' => 'US'],
            ['id' => 2, 'country_id' => 1, 'name' => 'California', 'country_code' => 'US'],
            ['id' => 3, 'country_id' => 2, 'name' => 'Ontario', 'country_code' => 'CA'],
        ];

        foreach ($states as $state) {
            DB::table('states')->updateOrInsert(['id' => $state['id']], $state);
        }

        $cities = [
            ['id' => 1, 'country_id' => 1, 'country_code' => 'US', 'state_id' => 1, 'name' => 'Atlanta'],
            ['id' => 2, 'country_id' => 1, 'country_code' => 'US', 'state_id' => 1, 'name' => 'Decatur'],
            ['id' => 3, 'country_id' => 1, 'country_code' => 'US', 'state_id' => 1, 'name' => 'Marietta'],
            ['id' => 4, 'country_id' => 1, 'country_code' => 'US', 'state_id' => 1, 'name' => 'East Point'],
            ['id' => 5, 'country_id' => 1, 'country_code' => 'US', 'state_id' => 2, 'name' => 'Los Angeles'],
            ['id' => 6, 'country_id' => 2, 'country_code' => 'CA', 'state_id' => 3, 'name' => 'Toronto'],
        ];

        foreach ($cities as $city) {
            DB::table('cities')->updateOrInsert(['id' => $city['id']], $city);
        }

        // The lookup endpoints and the property form cache these lists forever.
        cache()->flush();
    }
}
