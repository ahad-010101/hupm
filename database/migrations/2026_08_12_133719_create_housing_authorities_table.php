<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `housing_authorities`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housing_authorities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            // [GATE Q-2] Whether the authority remits per tenant or as a lump
            // sum is unanswered. Defaulting to per_tenant; a lump_sum answer
            // adds an allocation screen to WP-12 (risk R-9).
            $table->enum('remittance_type', ['per_tenant', 'lump_sum'])->default('per_tenant');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housing_authorities');
    }
};
