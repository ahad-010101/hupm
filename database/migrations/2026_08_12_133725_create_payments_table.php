<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `payments` — the gateway-facing record.
 *
 * A payment row is not money in the ledger. INVARIANT I-6: a payment reduces a
 * balance only on settlement, never at submission, because ACH takes 2–5
 * business days and can still be returned afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->enum('payer', ['tenant', 'housing_authority'])->default('tenant');
            $table->decimal('amount', 10, 2); // always positive; direction lives in the ledger entry
            $table->enum('method', [
                'echeck', 'card', 'cheque', 'money_order', 'cash', 'bank_transfer', 'ha_remittance',
            ]);
            $table->enum('gateway', ['authorize_net', 'manual'])->default('manual');
            $table->string('gateway_transaction_id', 60)->nullable()->unique();
            $table->foreignId('payment_profile_id')->nullable()->constrained()->nullOnDelete();

            // Guards against double submission — a tenant double-clicking Pay, or
            // a retried request, must not create two payments (risk R-13).
            $table->char('idempotency_key', 36)->unique();

            $table->enum('status', ['pending', 'settled', 'returned', 'failed', 'void'])->default('pending');
            $table->string('return_code', 10)->nullable();      // ACH return code, e.g. R01
            $table->string('return_description')->nullable();
            $table->string('batch_id', 60)->nullable();          // settlement batch
            $table->string('reference', 100)->nullable();        // cheque number, remittance ref

            $table->timestamp('submitted_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            // Set for admin-recorded payments, which must work at all times —
            // including during Management Review (invariant I-12).
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('tenant_id');
            $table->index('submitted_at');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
