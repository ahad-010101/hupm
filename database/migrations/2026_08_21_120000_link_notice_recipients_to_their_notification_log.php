<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Join a notice recipient to the notification that carries its outcome.
 *
 * `notice_recipients` and `notification_logs` were given the *same* six-value
 * status enum and no way to reach each other. So the recipient row was written
 * `queued` when the notice was composed and never touched again: the send job
 * marked the log `sent`, the bounce webhook marked the log `bounced`, and the
 * notice screen went on showing `queued` for everybody, forever. Its "delivered"
 * count could only ever be zero.
 *
 * That defeats what the recipient list is for (AC-NTF-04/05): proving a notice
 * reached people, and showing who it did not reach.
 *
 * The log is the source of truth — it is what the provider's webhook can find,
 * because a bounce arrives hours later carrying only a message id. This gives
 * the recipient row a way to follow it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notice_recipients', function (Blueprint $table) {
            $table->foreignId('notification_log_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('notification_logs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notice_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('notification_log_id');
        });
    }
};
