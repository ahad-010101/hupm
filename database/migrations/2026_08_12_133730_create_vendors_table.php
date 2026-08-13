<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `vendors` — maintenance contractors.
 *
 * A data record only. There is no vendor login (NG-6): tenants see that a
 * contractor is assigned, never vendor cost or invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('trade', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes(); // soft deletes apply to tenants and vendors only (DB §A4)

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
