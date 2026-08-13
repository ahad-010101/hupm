<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `payment_arrangements` — partial payment agreements (FR-ARR-01). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_arrangements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();

            // Snapshots at the time of agreement. remaining_balance is what the
            // arrangement says is left, which is deliberately not the same thing
            // as the live ledger balance — that stays a SUM over ledger_entries
            // (invariant I-1). This column is a term of the agreement, not a
            // cached balance.
            $table->decimal('total_owed', 10, 2);
            $table->decimal('amount_accepted', 10, 2);
            $table->decimal('remaining_balance', 10, 2);

            $table->json('schedule_json')->nullable();   // instalment dates and amounts
            $table->boolean('late_fees_continue');        // must be stated in the agreement
            $table->text('default_terms');                // breach consequences
            $table->enum('status', ['draft', 'pending_signature', 'active', 'completed', 'defaulted'])
                ->default('draft');
            $table->foreignId('document_id')->nullable()->constrained()->restrictOnDelete(); // generated PDF
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['lease_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_arrangements');
    }
};
