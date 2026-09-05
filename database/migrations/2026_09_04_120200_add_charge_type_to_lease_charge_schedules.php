<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which type created this schedule.  [WP-41]
 *
 * The schedule keeps its own category, description, amount and payer — copied
 * at creation, and authoritative from then on. This column is not how the
 * posting job finds them (`ChargePostingService` is untouched); it is what makes
 * re-running the bulk screen an UPDATE rather than a duplicate.
 *
 * The unique index is the D-03 trick again: `(lease_id, charge_type_id)` with a
 * NULLABLE column. MySQL permits unlimited NULLs in a unique index, so every
 * hand-made schedule that predates this — and any created without a type — is
 * unaffected, while a type can attach to a lease exactly once.
 *
 * That is what makes "post garbage to all 26 again at the new price" safe with
 * two admins doing it at once: the second insert collides rather than producing
 * a second $25 line on somebody's ledger every month forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_charge_schedules', function (Blueprint $table) {
            $table->foreignId('charge_type_id')
                ->nullable()
                ->after('lease_id')
                // RESTRICT, not cascade: deleting a type must never silently
                // stop billing 26 residents. Retire it with `active` instead.
                ->constrained()
                ->restrictOnDelete();

            $table->unique(['lease_id', 'charge_type_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lease_charge_schedules', function (Blueprint $table) {
            $table->dropUnique(['lease_id', 'charge_type_id']);
            $table->dropConstrainedForeignKey('charge_type_id');
        });
    }
};
