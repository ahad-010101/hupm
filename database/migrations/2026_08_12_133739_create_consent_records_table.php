<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `consent_records` — E-SIGN Act consent to electronic records.
 *
 * Immutable. The hash pins which wording the user actually agreed to, so a later
 * revision of the consent text cannot be retroactively attributed to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('consent_type', ['electronic_records']);
            $table->string('consent_text_version', 20);
            $table->char('consent_text_sha256', 64);
            $table->string('ip_address', 45);
            $table->string('user_agent', 500);
            $table->timestamp('agreed_at');

            $table->index(['user_id', 'consent_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
