<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `notification_logs` — every outbound message.
 *
 * NotificationService writes here BEFORE and AFTER dispatch, so a crash
 * mid-send leaves evidence rather than silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            // Email only in v1. SMS is out of scope (NG-4) because A2P 10DLC
            // registration is outside project control — but the column is an enum
            // so adding a channel stays a migration, not a redesign.
            $table->enum('channel', ['email']);
            $table->string('template', 100);
            $table->string('subject')->nullable();
            $table->string('recipient')->nullable();
            $table->enum('status', [
                'queued', 'sent', 'delivered', 'bounced', 'failed', 'not_deliverable',
            ])->default('queued');
            $table->string('provider_message_id', 100)->nullable();
            $table->text('error')->nullable();       // retained after 3 failed retries (AC-NTF-02)
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
            $table->index('template');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
