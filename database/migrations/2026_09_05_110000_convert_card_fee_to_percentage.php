<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The card fee becomes a percentage.  [WP-39, Q-7a — changed 2026-09-05]
 *
 * `payments.card_convenience_fee` held a flat dollar amount. It is replaced by
 * `payments.card_convenience_fee_percent`, which holds a percentage.
 *
 * **A rename rather than a reinterpretation.** Leaving the old key and changing
 * what its number means would turn a configured $4.95 fee into a 4.95% one on
 * the next deploy — on a $900 payment, $44.55 instead of $4.95, silently, to a
 * resident. A new key cannot be misread by anything that has not been updated
 * to look for it.
 *
 * Nothing is carried across, because there is nothing to carry: the flat fee
 * shipped at 0.00 on 4 Sep, has never been deployed, and a dollar amount does
 * not convert to a percentage without knowing the payment it applied to.
 *
 * The old row is deleted rather than left behind. A settings table with a dead
 * key in it invites somebody to edit the dead key.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'payments.card_convenience_fee_percent',
            'value' => '0.00',
            'type' => 'string',
            'description' => 'Q-7a. Percentage of the payment added to a card transaction. '
                .'Capped at 4% — it is a surcharge in card-brand terms.',
            // No `created_at`: the settings table has only `updated_at`
            // (see its own migration). Naming a column that does not exist is
            // what broke this migration on the first attempt.
            'updated_at' => now(),
        ]);

        DB::table('settings')->where('key', 'payments.card_convenience_fee')->delete();
    }

    public function down(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'payments.card_convenience_fee',
            'value' => '0.00',
            'type' => 'string',
            'description' => 'Q-7a. Flat fee added to a card payment.',
            // No `created_at`: the settings table has only `updated_at`
            // (see its own migration). Naming a column that does not exist is
            // what broke this migration on the first attempt.
            'updated_at' => now(),
        ]);

        DB::table('settings')->where('key', 'payments.card_convenience_fee_percent')->delete();
    }
};
