<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `ledger_entries` — the single financial table.
 *
 * Charges, payments, adjustments and reversals are all rows here, separated by
 * `type`. One table means one balance query and nothing to reconcile between
 * tables.
 *
 * INVARIANT I-1  There is no balance column here or anywhere else. A balance is
 *                SUM(amount) at read time, never stored, never cached (BR-03).
 * INVARIANT I-2  LedgerService is the only class permitted to write this table.
 * INVARIANT I-3  Rows are immutable except `status`. Corrections are reversing
 *                entries, never edits or deletes (BR-04).
 * INVARIANT I-10 Money is DECIMAL(10,2) here and App\Support\Money in PHP —
 *                never a float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            // Denormalised from the lease so the balance query does not join.
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            $table->enum('type', ['charge', 'payment', 'adjustment', 'reversal']);
            $table->enum('category', ['rent', 'late_fee', 'returned_fee', 'utility', 'deposit', 'other']);

            // INVARIANT I-4: a tenant must never see the housing_authority rows —
            // not in the UI, not in Inertia props, not in an export. Every
            // tenant-facing query filters payer = 'tenant'.
            $table->enum('payer', ['tenant', 'housing_authority']);

            // Signed: charges positive, payments and credits negative. This is
            // what makes the balance a plain SUM.
            $table->decimal('amount', 10, 2);

            $table->enum('status', ['pending', 'posted', 'cleared', 'returned', 'void'])->default('posted');
            $table->date('posted_on');                    // effective date, resolved via BusinessCalendar
            $table->char('period', 7)->nullable();        // 'YYYY-MM' for recurring charges
            $table->string('description');

            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->string('reason', 500)->nullable();    // mandatory for adjustment and reversal
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // [DEVIATION D-01] The spec's unique index
            //   (lease_id, period, category, payer) WHERE type='charge'
            // cannot be built and would be wrong if it could:
            //   1. MySQL 8 has no partial/conditional unique index.
            //   2. It permits only ONE late_fee row per lease per month, which
            //      directly forbids the daily late fees of FR-FEE-01 step 2.
            //   3. It permits only ONE utility charge per period, which forbids
            //      a lease having several lease_charge_schedules.
            // Replaced by an explicit idempotency key composed by LedgerService.
            // NULL for every non-charge row; MySQL allows unlimited NULLs in a
            // unique index, so payments and adjustments are unaffected.
            //
            //   rent                  {lease}:rent:{YYYY-MM}:{payer}
            //   recurring schedule    {lease}:sched{schedule_id}:{YYYY-MM}
            //   flat late fee         {lease}:latefee_flat:{YYYY-MM}
            //   daily late fee        {lease}:latefee_daily:{YYYY-MM-DD}
            //   returned-payment fee  {lease}:returnedfee:pmt{payment_id}
            //   imported opening bal. {lease}:opening
            $table->string('charge_key', 120)->nullable()->unique();

            // [DEVIATION D-04] The spec states "no updated_at — rows are
            // immutable", yet `status` must transition (pending → cleared,
            // cleared → returned). A table cannot be both. The column exists;
            // immutability is enforced by the App\Concerns\Immutable model guard
            // (WP-02), which allowlists only `status` and `updated_at` and
            // rejects deletes outright. Enforcing it in the model rather than by
            // omitting a column also lets us audit every transition.
            $table->timestamps();

            $table->index(['tenant_id', 'payer', 'status']);
            $table->index(['lease_id', 'posted_on']);
            $table->index('payment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
