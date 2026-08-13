<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB §A3 `documents` — the document vault.
 *
 * Files live outside the web root under randomised filenames and are served
 * only through an authenticated controller with short-lived signed URLs
 * (AC-DOC-02, AC-DOC-03). There is no S3 on this host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->restrictOnDelete();

            // 15 categories, FR-DOC-01 / UI §3.7.
            $table->enum('category', [
                'current_lease', 'renewal', 'hap_contract', 'tenancy_addendum',
                'move_in_inspection', 'move_out_inspection', 'lead_paint_disclosure',
                'house_rules', 'payment_agreement', 'late_notice', 'maintenance_notice',
                'rent_increase_notice', 'insurance', 'security_deposit_record', 'correspondence',
            ]);

            $table->string('title', 200);
            $table->string('original_filename');
            $table->string('stored_path', 500);   // outside web root, UUID filename
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);           // integrity; also the signing evidence (BR-26)

            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->boolean('is_signed')->default(false);
            $table->boolean('visible_to_tenant')->default(true);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // [DEVIATION D-04] Spec says "immutable once signed" and lists no
            // updated_at, yet is_signed must flip. Column exists; the Immutable
            // model guard blocks all writes once is_signed is true.
            $table->timestamps();

            $table->index(['tenant_id', 'category']);
            $table->index('is_signed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
