<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a repair cost, and who paid for it.  [WP-45, FR-MNT-03]
 *
 * `maintenance_requests` has carried no money at all — not what the work cost,
 * not whether anybody was billed. Both are ordinary questions the office asks,
 * and neither had an answer in the system.
 *
 * The two amounts are **different facts and never the same number**:
 *
 *   `cost_amount`   what the contractor charged the landlord. Feeds spend
 *                   reporting per property. **Never reaches a resident.**
 *   `billed_amount` what the resident was charged, when the damage was theirs.
 *                   Often nothing, sometimes a part, occasionally the whole.
 *
 * `billed_ledger_entry_id` is the link back to the money. The charge itself is
 * an ordinary ledger row (I-2) keyed `{lease}:ticket{id}`, so closing a ticket
 * twice cannot bill twice; this column exists so a ticket and its charge stay
 * findable from each other a year later, when somebody asks what the $180 was.
 *
 * RESTRICT: the ledger row cannot be deleted while a ticket points at it, which
 * is moot in practice because ledger rows are never deleted at all (I-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->decimal('cost_amount', 10, 2)->nullable()->after('close_reason');
            $table->decimal('billed_amount', 10, 2)->nullable()->after('cost_amount');
            $table->string('billed_reason', 500)->nullable()->after('billed_amount');
            $table->foreignId('billed_ledger_entry_id')->nullable()->after('billed_reason')
                ->constrained('ledger_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('billed_ledger_entry_id');
            $table->dropColumn(['cost_amount', 'billed_amount', 'billed_reason']);
        });
    }
};
