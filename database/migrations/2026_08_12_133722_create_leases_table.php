<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `leases`. Carries the whole financial configuration for a tenancy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('housing_authority_id')->nullable()->constrained()->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            // tenant_portion + ha_portion must equal total_contract_rent
            // (FR-REG-02). Enforced in the domain layer, not by a CHECK, because
            // the error has to reach the admin as a field-level message.
            $table->decimal('total_contract_rent', 10, 2);
            $table->decimal('tenant_portion', 10, 2);
            $table->decimal('ha_portion', 10, 2)->default(0);

            $table->unsignedTinyInteger('rent_due_day')->default(1);      // 1–28
            $table->unsignedTinyInteger('grace_period_days')->default(5);

            // Late fees apply to the tenant portion only, never to the housing
            // authority portion (invariant I-7, BR-09).
            $table->decimal('late_fee_flat', 8, 2)->default(0);
            $table->decimal('late_fee_daily', 8, 2)->default(0);
            $table->decimal('late_fee_max', 8, 2)->nullable();            // NULL = uncapped
            $table->decimal('returned_payment_fee', 8, 2)->default(0);

            // [GATE Q-12] Whether deposits are tracked as a ledger liability is
            // unanswered; for now this is a lease field only, not a ledger entry.
            $table->decimal('security_deposit', 10, 2)->default(0);

            $table->boolean('is_subsidised')->default(false);
            $table->string('hap_contract_number', 60)->nullable();
            $table->string('utility_responsibility')->nullable();

            $table->enum('partial_payment_policy', [
                'full_only', 'partial_allowed', 'before_due_only', 'under_arrangement_only',
            ])->default('full_only');
            $table->decimal('partial_minimum_amount', 10, 2)->nullable();
            $table->date('partial_policy_expires_on')->nullable();
            $table->boolean('partial_requires_approval')->default(true);

            // [GATE C1] "Full ledger required" is undefined in the source
            // material. Modelled as: admin marks the period's ledger reviewed.
            $table->boolean('ledger_review_required')->default(false);

            $table->enum('delinquency_state', ['current', 'management_review'])->default('current');
            $table->date('delinquency_since')->nullable();
            $table->enum('status', ['draft', 'active', 'ended'])->default('active');
            $table->timestamps();

            // [DEVIATION D-03] "One active lease per unit" (AC-REG-04) is
            // specified as "partial unique index OR application check". An
            // application check races: two admins activating leases on the same
            // unit simultaneously both pass validation, then both insert. MySQL 8
            // cannot express a partial index, so a generated column carries
            // unit_id only while the lease is active and NULL otherwise —
            // unlimited NULLs are permitted in a unique index.
            $table->unsignedBigInteger('active_unit_key')
                ->nullable()
                ->storedAs("IF(status = 'active', unit_id, NULL)")
                ->unique();

            $table->index('unit_id');
            $table->index('tenant_id');
            $table->index('status');
            $table->index('rent_due_day');
            $table->index('delinquency_state');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
