<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [DEVIATION D-28] The decomposition of a card payment.  [WP-39]
 *
 * `payments.amount` is the total charged at the gateway, which for ACH has
 * always equalled the rent paid because the fee was zero. A card convenience
 * fee separates the two: the tenant settles $345.98 of rent and Authorize.Net
 * takes $350.93. Reconciliation matches our row against the settlement record,
 * and the settlement record carries what was actually taken — so `amount` has
 * to be the larger number or every card settlement mismatches by the fee.
 *
 * This column records the part of it that was not rent. NULL and 0.00 both mean
 * "no fee": NULL for every ACH payment written before this migration, 0.00 for
 * a card payment taken while the fee setting is zero. Nothing is backfilled,
 * because there is nothing to backfill — no card payment exists yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Same precision as the fee columns on `leases`, not the 10,2 of
            // `amount` — a convenience fee that needed eight digits would be a
            // bug, and the narrower column says so.
            $table->decimal('convenience_fee', 8, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('convenience_fee');
        });
    }
};
