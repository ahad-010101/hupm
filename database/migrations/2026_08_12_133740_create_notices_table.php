<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `notices` — manual admin notices. Immutable once sent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 150);
            $table->text('body');                     // sanitised HTML
            $table->enum('audience_type', ['tenant', 'property', 'selected', 'all']);
            $table->json('audience_ref')->nullable(); // ids the audience_type refers to
            // Recorded at send time. Not a live count: the audience may change
            // afterwards, and the record must show who it actually went to.
            $table->unsignedInteger('recipient_count')->default(0);
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
