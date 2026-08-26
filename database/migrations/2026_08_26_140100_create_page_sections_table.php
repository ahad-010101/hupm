<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One block of a public page.  [WP-36, DEVIATION D-27]
 *
 * A page is an ordered list of typed sections rather than a body of HTML. The
 * difference is the whole point: an admin can rearrange a page, switch a block
 * off, and rewrite every word of it, and the design still holds — because what
 * they are editing is fields, not markup.
 *
 * `payload` is JSON because the fields differ per type, following the existing
 * precedent in `payment_arrangements.schedule_json`, `notices.audience_ref` and
 * `audit_logs.changes`. What may appear in it is not open: `SectionCatalogue`
 * describes each type's fields and is the same class that validates the post,
 * so the form cannot offer what the validator would reject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_sections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('content_page_id')->constrained()->cascadeOnDelete();

            // A key from SectionCatalogue. Not an enum: a new section type is a
            // deploy either way (it needs a Blade partial), and an enum would
            // make that a migration as well for no extra safety — the renderer
            // skips a type it has no partial for.
            $table->string('type', 40);

            $table->unsignedSmallInteger('position')->default(0);

            // Switched off rather than deleted: a seasonal block is worth
            // keeping the words of, and deleting to hide is how copy is lost.
            $table->boolean('is_enabled')->default(true);

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['content_page_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_sections');
    }
};
