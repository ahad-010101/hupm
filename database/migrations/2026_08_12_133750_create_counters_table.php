<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `counters` — [DERIVED, DEVIATION D-09]
 *
 * Not in DB §A3. The spec requires maintenance_requests.ticket_number to be
 * "unique, sequential" but names no generator. MAX(ticket_number)+1 races: two
 * tenants submitting at once read the same maximum and one insert fails, or
 * worse, numbers skip and the sequence stops being evidence of order.
 *
 * A row is locked with SELECT … FOR UPDATE, incremented, and released inside
 * the same transaction as the insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
