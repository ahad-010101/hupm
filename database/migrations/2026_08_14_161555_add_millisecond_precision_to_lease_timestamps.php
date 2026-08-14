<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Millisecond precision on lease timestamps.  [WP-07, DEVIATION D-18]
 *
 * FS §18.4 requires optimistic locking on `updated_at` so two admins editing
 * one lease cannot silently overwrite each other. MySQL's `TIMESTAMP` stores
 * whole seconds, which makes that check unreliable in exactly the case it
 * exists for: two saves inside the same second produce an identical
 * `updated_at`, the second edit looks current, and one admin's fee
 * configuration quietly replaces the other's with neither of them told.
 *
 * Discovered because the WP-07 test for §18.4 could not be made to fail — the
 * two requests landed in the same second.
 *
 * Three digits is enough. Two people clicking Save in the same millisecond is
 * not a scenario worth engineering for; the same second plainly is.
 *
 * Applied to `leases` only. Other tables do not carry optimistic locking, and
 * widening every timestamp would be churn for no benefit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->timestamp('created_at', 3)->nullable()->change();
            $table->timestamp('updated_at', 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });
    }
};
