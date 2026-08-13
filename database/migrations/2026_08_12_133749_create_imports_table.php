<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `imports` — data import runs (WP-08, the M1 gate).
 *
 * dry_run rows are kept, not discarded: the report of what would have happened
 * is part of the evidence trail behind the opening balances the client signs
 * off (Q-5, risk R-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->enum('status', ['dry_run', 'confirmed', 'failed'])->default('dry_run');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_created')->default(0);
            $table->unsignedInteger('rows_warning')->default(0);
            $table->unsignedInteger('rows_error')->default(0);
            $table->json('report_json')->nullable();  // per-row line numbers and reasons
            $table->foreignId('run_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
