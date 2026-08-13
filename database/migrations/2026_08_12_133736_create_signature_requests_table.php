<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `signature_requests`.
 *
 * First-party e-signature — no third-party service (TDD §2). The legal weight
 * comes from the evidence chain in signature_events, not from this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // the signer
            $table->enum('status', ['pending', 'viewed', 'signed', 'declined', 'expired'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // The executed PDF is a separate document row — the original is never
            // overwritten, so both the unsigned and signed bytes are retained.
            $table->foreignId('signed_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_requests');
    }
};
