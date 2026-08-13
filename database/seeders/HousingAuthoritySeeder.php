<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A placeholder Georgia housing authority so leases can be created before the
 * client supplies real agency details.
 *
 * remittance_type is the visible face of Q-2: if the authority turns out to
 * remit as a lump sum rather than per tenant, WP-12 gains an allocation screen
 * (risk R-9). The default is per_tenant.
 */
class HousingAuthoritySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('housing_authorities')->updateOrInsert(
            ['name' => 'Georgia DCA — Housing Choice Voucher (placeholder)'],
            [
                'contact_name' => null,
                'contact_email' => null,
                'contact_phone' => null,
                'remittance_type' => 'per_tenant', // [GATE Q-2]
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
