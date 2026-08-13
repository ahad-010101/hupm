<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `units`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            // RESTRICT: a property with units cannot be deleted (DB §A4).
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('unit_number', 20);
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 3, 1)->nullable();
            $table->enum('status', ['occupied', 'vacant', 'off_market'])->default('vacant');
            $table->timestamps();

            $table->unique(['property_id', 'unit_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
