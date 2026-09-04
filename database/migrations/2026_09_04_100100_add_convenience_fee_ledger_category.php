<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A ledger category for the card convenience fee.  [WP-39, FR-PAY-01]
 *
 * `other` would have avoided a migration and would have been the wrong answer:
 * every report in FR-ADM-02 groups by category, so a fee filed under `other`
 * is a fee nobody can total, reconcile against the merchant statement, or
 * explain to a tenant who asks what the extra line is.
 *
 * Raw SQL rather than the schema builder. Changing an enum is MySQL-specific
 * whichever way it is written, and production is **5.7, not the specified 8**
 * (see the WP-38 note) — an explicit MODIFY COLUMN behaves identically on both
 * and leaves no doubt about what was generated.
 *
 * Widening an enum is additive: no existing row changes, and nothing is
 * rewritten that was not already valid.
 */
return new class extends Migration
{
    private const OLD = "'rent','late_fee','returned_fee','utility','deposit','other'";

    private const NEW = "'rent','late_fee','returned_fee','utility','deposit','other','convenience_fee'";

    public function up(): void
    {
        DB::statement('ALTER TABLE ledger_entries MODIFY COLUMN category ENUM('.self::NEW.') NOT NULL');
    }

    public function down(): void
    {
        // Narrowing is not additive: any row already using the value would be
        // silently coerced to '' by MySQL. Refuse instead of corrupting a
        // ledger, which I-3 does not permit under any circumstances.
        $inUse = DB::table('ledger_entries')->where('category', 'convenience_fee')->count();

        if ($inUse > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$inUse} ledger entries use the convenience_fee category. "
                .'Reverse them with reversing entries first (I-3); they are never deleted or edited.'
            );
        }

        DB::statement('ALTER TABLE ledger_entries MODIFY COLUMN category ENUM('.self::OLD.') NOT NULL');
    }
};
