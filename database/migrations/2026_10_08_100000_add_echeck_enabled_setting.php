<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bank transfers become switchable.  [WP-56]
 *
 * eCheck.Net is a separate service from the Authorize.Net gateway account, and
 * this one does not have it: `getMerchantDetails` lists `productCodes: [CNP]`
 * and `paymentMethods: [MasterCard, Visa]`.
 *
 * The failure is silent, which is what makes it dangerous. The hosted page does
 * not refuse `showBankAccount` on an account without eCheck — it ignores it and
 * renders a card form. A resident who chose "Bank account", and was quoted
 * 0.75%, is handed a card page; whatever they type is recorded against a
 * payment this system believes is an eCheck charged at the bank rate.
 *
 * Defaults to `true`, which is the behaviour every installation has had until
 * now. It is set to false on the live host until eCheck.Net is enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'payments.echeck_enabled',
            'value' => 'true',
            'type' => 'bool',
            'description' => 'WP-56. Whether bank transfers are offered. Must be false unless '
                .'eCheck.Net is live on the merchant account.',
            'is_gated' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'payments.echeck_enabled')->delete();
    }
};
