<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `recurring_payments` — autopay configuration. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_payments', function (Blueprint $table) {
            $table->id();
            // One autopay arrangement per lease.
            $table->foreignId('lease_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('payment_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_month'); // 1–28, so it exists in February
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');
            $table->string('suspended_reason')->nullable();
            // Idempotency for the autopay job: a re-run on the same day, or a
            // catch-up run after a missed day, must not charge twice
            // (invariant I-8).
            $table->char('last_run_period', 7)->nullable();
            $table->timestamps();

            $table->index(['status', 'day_of_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_payments');
    }
};
