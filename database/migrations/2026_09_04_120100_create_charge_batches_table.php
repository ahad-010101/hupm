<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One bulk posting, so it can be undone as one act.  [WP-41]
 *
 * Without this, charging 26 residents by mistake means finding and reversing 26
 * ledger rows by hand, on 26 screens, getting all of them. That is not an undo,
 * it is a penance — and the mistake it punishes is the one this feature makes
 * easy to commit.
 *
 * The batch does not hold the money. Every charge is an ordinary ledger row
 * with its own `charge_key` of `{lease}:batch{id}`; this table records that
 * they were posted together, by whom, and whether they were reversed. Reversing
 * posts a reversing entry per charge and both rows stay visible (I-3).
 *
 * Only one-off postings get a batch. A monthly schedule is undone by
 * deactivating it, which stops future postings and alters nothing already
 * posted — a different act with a different meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_batches', function (Blueprint $table) {
            $table->id();

            // Nullable and RESTRICT: a batch outlives the type that made it, and
            // the type cannot be deleted while a batch remembers it.
            $table->foreignId('charge_type_id')->nullable()->constrained()->restrictOnDelete();

            // Copied, not joined. What was actually charged, in the words the
            // residents saw, even if the type is edited afterwards.
            $table->string('description', 150);
            $table->decimal('amount', 10, 2);
            $table->enum('payer', ['tenant', 'housing_authority'])->default('tenant');
            $table->unsignedSmallInteger('lease_count');
            $table->decimal('total_amount', 12, 2);

            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');

            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversed_reason', 500)->nullable();

            $table->timestamps();

            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_batches');
    }
};
