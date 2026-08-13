<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `properties`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('street_address');
            $table->string('city', 100);
            $table->char('state', 2)->default('GA');
            $table->char('zip', 5);
            $table->string('county', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // The weather job groups properties by ZIP so one NWS call covers
            // every property in a zone (WP-21).
            $table->index('zip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
