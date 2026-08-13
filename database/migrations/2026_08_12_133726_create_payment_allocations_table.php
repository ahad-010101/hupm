<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `payment_allocations` — which payment paid which charge.
 *
 * A join table rather than implied arithmetic, so the waterfall (Q-1) is
 * auditable: you can point at a charge and say which payment cleared it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_entry_id')->constrained('ledger_entries')->restrictOnDelete();
            $table->decimal('amount', 10, 2); // positive

            // [DEVIATION D-02] FR-PAY-04 step 4 requires a returned payment to
            // restore its charges to unpaid, but the specified schema has no
            // mechanism for that: the allocations would linger and every
            // outstanding-per-charge calculation would understate what is owed —
            // silently, and only for tenants whose payment bounced.
            //
            // Reversal is a timestamp, not a delete. The allocation is evidence
            // of what happened and must survive; queries for outstanding amounts
            // exclude rows where this is set.
            $table->timestamp('reversed_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_id', 'charge_entry_id']);
            $table->index(['charge_entry_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
