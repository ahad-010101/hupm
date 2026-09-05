<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named, reusable reasons to charge a resident.  [WP-41, FR-CHG-01]
 *
 * "Garbage collection", "Pest control", "Lawn maintenance". The purpose IS the
 * template — one table rather than a category list and a preset list that drift
 * apart until nobody knows which one decides the wording on a ledger row.
 *
 * A type is copied, not referenced, when a schedule is created from it. Editing
 * the default amount changes what the NEXT one is created with and leaves every
 * existing schedule alone: money already agreed with a resident must not move
 * because somebody edited a dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();

            // The narrower of the two enums. `ledger_entries.category` also has
            // rent, late_fee, returned_fee, deposit and convenience_fee — every
            // one of which is posted by an engine that owns it, and none of
            // which an admin should be able to hand-post from a dropdown.
            $table->enum('category', ['utility', 'other'])->default('other');

            $table->string('default_description', 150);
            $table->decimal('default_amount', 10, 2)->default(0);

            // Defaults to tenant, and in practice never anything else — but the
            // schedules table carries the column, so the type has to be able to
            // say which it means.
            $table->enum('payer', ['tenant', 'housing_authority'])->default('tenant');

            // Retire a type without deleting it. The schedules and ledger rows
            // it created stay readable, which a delete would take away.
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_types');
    }
};
