<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A bank transfer can carry a fee too.  [WP-47, Q-7b]
 *
 * WP-39 gave cards a fee and left ACH free, on the reasoning that ACH is nearly
 * free to us. Nearly is not free: each transfer costs about 25c, and the client
 * asked to recover it.
 *
 * **Flat, where the card fee is a percentage.** The two rails are billed
 * differently and the fee should follow the cost, not the other setting. A
 * percentage of a $1,000 bank transfer would be a markup on a 25c transaction
 * and hard to defend to the resident who asked why.
 *
 * Ships at 0.00 and gated, so nothing changes for anybody until somebody
 * decides it should. `is_gated` is set here as well as in the seeder because an
 * already-deployed database is migrated, never re-seeded — a row inserted
 * without it would silently escape the go-live register.
 */
return new class extends Migration
{
    private const KEY = 'payments.echeck_fee_flat';

    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => self::KEY,
            'value' => '0.00',
            'type' => 'string',
            'description' => 'Q-7b. Flat amount added to a bank-transfer payment. '
                .'0.00 means the landlord absorbs the cost.',
            'is_gated' => true,
            // No `created_at`: the settings table has only `updated_at`.
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
