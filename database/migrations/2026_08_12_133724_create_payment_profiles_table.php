<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `payment_profiles` — tokenised bank accounts.
 *
 * INVARIANT I-5 / BR-15: no account number, routing number or card number
 * column exists here or anywhere else in the schema, by design. Authorize.Net
 * CIM holds the instrument; we hold opaque profile IDs and a masked descriptor
 * such as "Checking ••••6789". An architecture test asserts this table gains no
 * such column later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_customer_profile_id', 60);
            $table->string('gateway_payment_profile_id', 60);
            $table->string('descriptor', 60);
            // needs_update is set by a NOC (notification of change) from the ACH
            // network — the account moved and the token must be refreshed.
            $table->enum('status', ['active', 'needs_update', 'removed'])->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_profiles');
    }
};
