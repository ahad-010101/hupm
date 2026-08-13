<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `delinquency_events` — every Management Review transition.
 *
 * Immutable: this is the record of why a tenant was placed under review, and it
 * may end up in a Georgia dispossessory conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delinquency_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->enum('from_state', ['current', 'management_review']);
            $table->enum('to_state', ['current', 'management_review']);
            $table->string('reason', 500);                  // mandatory (AC-DEL-05)
            // Snapshot, not a live lookup: the balance at the moment of the
            // decision is what justifies it, and it will drift afterwards.
            $table->decimal('balance_at_event', 10, 2);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete(); // NULL = system
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lease_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquency_events');
    }
};
