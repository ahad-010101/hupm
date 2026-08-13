<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `maintenance_events` — ticket lifecycle log. Immutable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 40)->nullable(); // NULL on the creating event
            $table->string('to_status', 40);
            $table->text('note')->nullable();
            // Internal notes exist: not every transition or comment is shown to
            // the tenant, and vendor cost never is.
            $table->boolean('visible_to_tenant')->default(true);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['maintenance_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_events');
    }
};
