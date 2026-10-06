<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The month this system becomes the record.  [WP-51]
 *
 * `BalanceOfPeriods` answers "what is due" from each lease's start date, which
 * is correct and was never the problem. The problem is that these tenancies run
 * back to 2007, so the first nightly run against the real portfolio posted
 * 3,474 months of rent against a system that was not keeping the books at the
 * time.
 *
 * Blank by default, which is exactly today's behaviour — nothing changes for
 * an installation that wants every month since the lease began. Not gated: it
 * is an operational boundary, not one of the twenty client questions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'charges.post_from_period',
            'value' => '',
            'type' => 'string',
            'description' => 'WP-51. First month this system is the record for; rent never posts '
                .'before it. Blank charges from each lease start date.',
            'is_gated' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'charges.post_from_period')->delete();
    }
};
