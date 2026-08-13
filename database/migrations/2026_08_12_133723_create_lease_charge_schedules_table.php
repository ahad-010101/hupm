<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `lease_charge_schedules` — recurring non-rent charges (utilities, pet
 * fee, parking). A lease may carry several, which is one of the two reasons the
 * specified charge unique index had to be replaced (see D-01 on ledger_entries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_charge_schedules', function (Blueprint $table) {
            $table->id();
            // CASCADE: a schedule is meaningless without its lease and holds no
            // financial record of its own — the posted charges live in the ledger.
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->enum('category', ['utility', 'other']);
            $table->string('description', 150);
            $table->decimal('amount', 10, 2);
            $table->enum('payer', ['tenant', 'housing_authority'])->default('tenant');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['lease_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_charge_schedules');
    }
};
