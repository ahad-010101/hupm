<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The users.tenant_id column is declared in the stock users migration, which
 * runs before tenants exists. The constraint is added here instead.
 *
 * RESTRICT, not CASCADE: deleting a tenant must never silently delete the login
 * that owns their audit trail. Tenants soft-delete anyway (DB §A4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });
    }
};
