<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [DEVIATION D-23] Somewhere to put a notice's attachments.
 *
 * WP-20 requires attachments on a notice (pdf/jpg/png, 10 MB, up to three) and
 * DB §A3 has nowhere to hold them: `notices` carries a subject and a body, and
 * `documents` is per tenant by construction — `tenant_id` is NOT NULL.
 *
 * Filing one `documents` row per recipient would fit the existing schema, and
 * was the first thing I tried on paper. It multiplies the bytes by the audience:
 * three 10 MB attachments to all twenty-six residents is 780 MB for one notice,
 * on shared hosting with a disk quota. The file is identical for every
 * recipient, so storing it once and checking membership on the way out is both
 * smaller and more honest about what the thing is.
 *
 * The tenant still sees it: notices and their attachments surface in the portal
 * and alongside the vault (FR-NTF-02), served through an authenticated
 * controller exactly as documents are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path', 500);  // outside the web root, UUID name
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index('notice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_attachments');
    }
};
