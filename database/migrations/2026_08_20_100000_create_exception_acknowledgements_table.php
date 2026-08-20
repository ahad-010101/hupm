<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [DEVIATION D-25] Somewhere to record that a person has SEEN an exception.
 *
 * AC-ADM-02: "a returned payment appears in the Exceptions panel **until
 * acknowledged**." Nothing in DB §A3 can hold that acknowledgement. A returned
 * payment is terminal — it never leaves `returned` — so without somewhere to
 * record attention, the panel it sits on can only grow, and a panel that never
 * empties is one nobody reads.
 *
 * Deliberately **not** columns on `payments`:
 *
 *   1. Acknowledgement is a fact about a person's attention, not about the
 *      money. Keeping it in its own table means the exceptions panel has no
 *      write path into a payment row at all.
 *   2. The panel carries six kinds of exception from four tables. One `kind`
 *      plus a subject id keeps the concept in one place rather than spreading
 *      the same nullable pair across every table that can raise one.
 *
 * Only the two terminal kinds are acknowledgeable — a returned payment and a
 * failed autopay. The other four clear themselves when the underlying thing is
 * fixed (a profile updated, a payment matched, a ticket triaged, an account
 * released), and an acknowledgement that hid one of those would hide work
 * rather than record it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exception_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('acknowledged_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            // One acknowledgement per thing. A second click is the same fact,
            // not a second one, so it is refused by the database rather than
            // left to whoever writes the next call site.
            $table->unique(['kind', 'subject_id']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_acknowledgements');
    }
};
