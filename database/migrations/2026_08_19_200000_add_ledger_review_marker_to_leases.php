<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [DEVIATION D-24] Somewhere to record that a ledger WAS reviewed.
 *
 * FR-ARR-01 lists "full ledger required" among the per-tenant partial-payment
 * settings and never defines it (contradiction C1). `leases.ledger_review_required`
 * exists and says the lease *needs* a review; nothing in DB §A3 can record that
 * one *happened*, which makes the flag unenforceable — it would either block a
 * partial payment forever or block nothing at all.
 *
 * A period rather than a boolean or a timestamp: the shipped interpretation is
 * "reviewed for the current period", so the question the policy asks is
 * "reviewed for 2026-08?" and the column answers it directly. A boolean would
 * need clearing every month by something, and a timestamp would need the same
 * arithmetic performed at every call site.
 *
 * **[GATE C1/Q-1]** The interpretation is ours, not the client's. Confirm
 * before go-live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->char('ledger_reviewed_period', 7)->nullable()->after('ledger_review_required');
            $table->foreignId('ledger_reviewed_by_user_id')->nullable()
                ->after('ledger_reviewed_period')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('ledger_reviewed_at')->nullable()->after('ledger_reviewed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('ledger_reviewed_by_user_id');
            $table->dropColumn(['ledger_reviewed_period', 'ledger_reviewed_at']);
        });
    }
};
