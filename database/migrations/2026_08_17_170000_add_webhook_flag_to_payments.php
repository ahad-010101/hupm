<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [DEVIATION D-21] Somewhere to put "the gateway told us something".
 *
 * API-HOOK-01 requires the webhook to "mark the payment for priority
 * reconciliation", and the specified schema has no field that can hold that
 * mark. Without one the endpoint can only write an audit row, and WP-14's
 * reconciliation job would have to mine the audit log to find out which
 * payments the gateway has spoken about — coupling the money path to a table
 * that exists for people to read.
 *
 * Deliberately a timestamp rather than a boolean: "flagged at 03:12" survives
 * being handled, and answers "did the webhook beat the settlement file?" — the
 * question that matters when a return is disputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('webhook_flagged_at')->nullable()->after('batch_id');
            $table->string('webhook_last_event', 60)->nullable()->after('webhook_flagged_at');

            // The reconciliation job's working set: flagged, not yet resolved.
            $table->index(['webhook_flagged_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['webhook_flagged_at', 'status']);
            $table->dropColumn(['webhook_flagged_at', 'webhook_last_event']);
        });
    }
};
