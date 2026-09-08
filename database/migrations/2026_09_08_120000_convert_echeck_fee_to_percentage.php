<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bank-transfer fee becomes a percentage.  [WP-47, Q-7b — same day]
 *
 * `payments.echeck_fee_flat` was added this morning on the belief that a bank
 * transfer costs a fixed ~25c. That is true of ACH in general and false of this
 * gateway: **Authorize.Net bills eCheck.Net as a discount rate of 0.75% per
 * transaction**, with no flat per-transaction fee on the published plan. A flat
 * fee therefore cannot track the cost — $2.50 is five times the cost of a $67
 * tenant portion and a third of the cost of a $1,023 one.
 *
 * **A rename, not a reinterpretation** — the same reasoning as the card fee's
 * conversion three hours earlier. Leaving the key and changing what its number
 * means would read a configured $2.50 as 2.50%, which on a $1,023 payment is
 * $25.58 charged silently to a resident. A new key cannot be misread by
 * anything that has not been updated to look for it.
 *
 * Nothing is carried across. The flat key existed for one morning, on one
 * branch, was never deployed, and a dollar amount does not convert to a
 * percentage without knowing the payment it applied to.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'payments.echeck_fee_percent',
            'value' => '0.00',
            'type' => 'string',
            'description' => 'Q-7b. Percentage of the payment added to a bank transfer. '
                .'Capped at 2% — the provider charges 0.75%.',
            'is_gated' => true,
            // No `created_at`: the settings table has only `updated_at`.
            'updated_at' => now(),
        ]);

        // Deleted rather than left behind. A settings table with a dead key in
        // it invites somebody to edit the dead key.
        DB::table('settings')->where('key', 'payments.echeck_fee_flat')->delete();
    }

    public function down(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'payments.echeck_fee_flat',
            'value' => '0.00',
            'type' => 'string',
            'description' => 'Q-7b. Flat amount added to a bank-transfer payment.',
            'is_gated' => true,
            'updated_at' => now(),
        ]);

        DB::table('settings')->where('key', 'payments.echeck_fee_percent')->delete();
    }
};
