<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `weather_alerts` — NWS alerts issued to properties. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('nws_alert_id', 200);
            $table->string('event_type', 100);
            $table->string('severity', 50)->nullable();
            $table->string('headline', 500)->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Deduplication (AC-NTF-07): the NWS re-publishes the same alert as
            // it updates, and tenants must not be emailed twice for one storm.
            $table->unique(['property_id', 'nws_alert_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_alerts');
    }
};
