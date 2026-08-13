<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // DB §A3 `users`. The tenant_id foreign key is added later, once the
        // tenants table exists — see add_tenant_id_to_users_table.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // NULL for admin and owner accounts. A tenant may also have no user
            // row at all: tenants without an email address cannot log in (Q-4).
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name', 150);
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // Nullable while the account is `invited` — admin creates the account
            // and the tenant sets their own password from the emailed link.
            $table->string('password')->nullable();
            $table->enum('role', ['admin', 'tenant', 'owner'])->default('tenant');
            $table->enum('status', ['invited', 'active', 'suspended'])->default('invited');
            $table->text('two_factor_secret')->nullable();          // reserved, unused in v1
            $table->timestamp('two_factor_confirmed_at')->nullable(); // reserved, unused in v1
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('role');
            $table->index('tenant_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
