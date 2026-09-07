<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two new kinds of person who can sign in.  [WP-43, WP-44]
 *
 * Every account until now has been an admin or a resident (`owner` exists in
 * the enum but is switched off — Q-11 closed no on 27 Aug). A housing authority
 * and a contractor are neither: each is an outside party with a narrow, real
 * reason to see part of the system.
 *
 * **An account links to exactly one subject.** `tenant_id` has always worked
 * this way and the rule is the same for both new columns: the subject is read
 * from the session and never from a request (I-9, BR-20). A route parameter
 * naming somebody else's agency or somebody else's tickets is a client
 * assertion, not a fact.
 *
 * RESTRICT on both, matching `tenant_id`: deleting an agency or a contractor
 * while an account points at it would leave a login belonging to nobody.
 * `vendors` soft-deletes, so removing a contractor ends their access without
 * destroying the record of who did the work.
 *
 * Widening an enum is additive — no existing row changes. Raw SQL because
 * changing an enum is MySQL-specific however it is written, and production is
 * **5.7, not the specified 8**.
 */
return new class extends Migration
{
    private const OLD = "'admin','tenant','owner'";

    private const NEW = "'admin','tenant','owner','housing_authority','vendor'";

    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY COLUMN role ENUM('.self::NEW.") NOT NULL DEFAULT 'tenant'");

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('housing_authority_id')->nullable()->after('tenant_id')
                ->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->after('housing_authority_id')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('housing_authority_id');
            $table->dropConstrainedForeignKey('vendor_id');
        });

        // Narrowing is not additive: MySQL coerces any row using a removed
        // value to ''. Refuse rather than silently orphaning a login.
        $inUse = DB::table('users')->whereIn('role', ['housing_authority', 'vendor'])->count();

        if ($inUse > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$inUse} accounts use the new roles. Remove or re-role them first."
            );
        }

        DB::statement('ALTER TABLE users MODIFY COLUMN role ENUM('.self::OLD.") NOT NULL DEFAULT 'tenant'");
    }
};
