<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `signature_events` — the evidence chain.
 *
 * This table is the reason a signature holds up. Immutable, append-only: no
 * update or delete route exists, and the Immutable model guard rejects both
 * (AC-AUD-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_request_id')->constrained()->restrictOnDelete();
            $table->enum('event', ['created', 'sent', 'opened', 'scrolled_complete', 'signed', 'declined']);
            $table->string('typed_name', 150)->nullable();
            // The exact text of the control the signer clicked, recorded as
            // evidence of intent — "I agree and sign" means something different
            // from "Continue", and a year later nobody will remember which.
            $table->string('button_label', 100)->nullable();
            // Hash of the document bytes at the moment of signing (BR-26): proves
            // what was signed, not merely that something was.
            $table->char('document_sha256', 64)->nullable();
            $table->string('ip_address', 45);   // IPv4 or IPv6
            $table->string('user_agent', 500);
            // Millisecond precision: ordering of scrolled_complete then signed
            // within the same second is itself evidence.
            $table->timestamp('occurred_at', 3);

            $table->index(['signature_request_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_events');
    }
};
