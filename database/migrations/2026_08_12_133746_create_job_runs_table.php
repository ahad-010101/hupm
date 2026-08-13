<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `job_runs` — scheduled job monitoring.
 *
 * On shared hosting a broken cron fails silently: no daemon, no alert, and the
 * only symptom is that nothing happens. An empty table for the day is how R-5
 * gets detected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 100);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable(); // NULL + status running = crashed mid-run
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->unsignedInteger('records_processed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['job_name', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
