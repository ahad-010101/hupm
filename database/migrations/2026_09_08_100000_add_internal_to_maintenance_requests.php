<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ticket the resident is not meant to see.  [WP-46, FR-MNT-01]
 *
 * Every ticket until now came from a resident, so every ticket was theirs by
 * construction. An admin raising one changes that: "replace the boiler when
 * this tenancy ends" is real work on a real unit and has no business appearing
 * on the resident's Repairs page.
 *
 * **Defaults to false, and that is the important half.** Work on somebody's
 * home is their business — a contractor arriving unannounced because the
 * ticket was hidden is worse than the reverse. Internal is the deliberate
 * exception, ticked one ticket at a time.
 *
 * The flag alone does not keep a secret. `Portal/MaintenanceController` filters
 * on it in both index and show, so a resident cannot reach one by guessing an
 * id (I-9), and `MaintenanceService::notifyTenant()` returns early on it, so no
 * email describes work the portal will not show. A filter without the
 * notification guard would leak the ticket by email while hiding it on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->boolean('internal')->default(false)->after('status');

            // The portal queries `tenant_id` AND `internal` together on every
            // read, which is the whole list a resident ever sees.
            $table->index(['tenant_id', 'internal']);
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'internal']);
            $table->dropColumn('internal');
        });
    }
};
