<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `maintenance_attachments` — photos, video, vendor invoices. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('kind', ['tenant_media', 'vendor_media', 'invoice']);
            $table->string('original_filename');
            $table->string('stored_path', 500); // outside web root, as with documents
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            // Invoices are kind='invoice' and must never be visible to a tenant.
            $table->boolean('visible_to_tenant')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['maintenance_request_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_attachments');
    }
};
