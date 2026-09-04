<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kind of instrument a saved profile holds.  [WP-39, FR-PAY-02]
 *
 * INVARIANT I-5 still holds and this column does not weaken it: `bank` or
 * `card` says what *kind* of thing Authorize.Net is holding for us, not what it
 * is. There is still no account number, no routing number, no card number and
 * no CVV anywhere in this schema, and the architecture test asserts it.
 *
 * Needed because the portal must offer the right saved methods for the method
 * the tenant chose — showing a saved Visa on a bank-only hand-off is an error
 * the tenant discovers on Authorize.Net's page, which is the worst place to
 * discover anything.
 *
 * Defaults to `bank`: every profile that exists today is one, since cards were
 * switched off until now (Q-7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_profiles', function (Blueprint $table) {
            $table->enum('instrument_type', ['bank', 'card'])
                ->default('bank')
                ->after('gateway_payment_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_profiles', function (Blueprint $table) {
            $table->dropColumn('instrument_type');
        });
    }
};
