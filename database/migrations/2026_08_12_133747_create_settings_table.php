<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `settings` — key/value configuration.
 *
 * Every gated client decision ships as a row here with a documented default, so
 * a late answer is a configuration change rather than a code change. The three
 * confirmation columns are [DERIVED]: they turn the decision register in
 * 06-Implementation-Plan.md §6 into enforceable state, and WP-35 cannot
 * complete while a gated row is unconfirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'int', 'bool', 'json'])->default('string');
            $table->string('description', 500)->nullable();

            // [DERIVED] Go-live decision register.
            $table->boolean('is_gated')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();

            $table->timestamp('updated_at')->nullable();

            $table->index('is_gated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
