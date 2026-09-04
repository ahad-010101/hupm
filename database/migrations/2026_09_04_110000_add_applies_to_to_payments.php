<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a payment is FOR.  [WP-40, Q-12]
 *
 * A security deposit is not rent and a resident does not pay it by accident.
 * Without this column the deposit is just another outstanding charge, and the
 * allocation order decides what a payment touches — under the shipped
 * `oldest_charge_first` a part payment would clear the deposit first and leave
 * the rent short, accruing a late fee on the rent while the deposit sat paid.
 *
 * So the resident chooses, and the choice is recorded rather than inferred:
 *
 *   `balance` — rent, fees, utilities. Every existing rule applies unchanged.
 *   `deposit` — the security deposit alone. Nothing else is touched.
 *
 * The two scopes are disjoint by design (see AllocationService): a `balance`
 * payment cannot reach the deposit and a `deposit` payment cannot reach the
 * rent. That is what keeps "paying the rent" behaving exactly as it did before
 * deposits existed.
 *
 * Defaults to `balance`, which is what every payment written before today was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('applies_to', ['balance', 'deposit'])
                ->default('balance')
                ->after('payer');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('applies_to');
        });
    }
};
