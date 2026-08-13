<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `notice_recipients` — per-recipient delivery record. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // Copied at send time, not joined: the record must show the address
            // actually used, even if the tenant later changes it.
            $table->string('email')->nullable();
            // not_deliverable is a first-class outcome, not a failure — a tenant
            // with no email address surfaces to admin rather than disappearing
            // (AC-NTF-03, Q-4).
            $table->enum('delivery_status', [
                'queued', 'sent', 'delivered', 'bounced', 'failed', 'not_deliverable',
            ])->default('queued');
            $table->string('provider_message_id', 100)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['notice_id', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_recipients');
    }
};
