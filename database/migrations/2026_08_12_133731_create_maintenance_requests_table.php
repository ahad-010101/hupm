<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DB §A3 `maintenance_requests` — tickets (FR-MNT-01). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            // [DEVIATION D-09] Spec requires "unique, sequential" with no
            // generator named. Sequential numbering from MAX()+1 collides or
            // skips under concurrent submission, so the value comes from the
            // `counters` table under SELECT … FOR UPDATE.
            $table->string('ticket_number', 20)->unique();

            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();

            // 12 categories, FR-MNT-01.
            $table->enum('category', [
                'plumbing', 'electrical', 'hvac', 'appliance', 'roof_leak', 'pest',
                'locks_doors', 'smoke_co_detector', 'structural', 'lawn_exterior',
                'section8_inspection_repair', 'other',
            ]);

            $table->text('description');                 // 10–5000 chars, enforced in the FormRequest
            $table->date('date_began')->nullable();
            $table->boolean('permission_to_enter');
            $table->enum('preferred_contact', ['email', 'phone']);
            $table->string('contact_phone', 30)->nullable();
            $table->boolean('pets_present');
            $table->string('best_access_time', 100)->nullable();

            // Tenant self-flags; admin sets urgency at triage (Q-17 governs the
            // definition of an emergency and the after-hours process).
            $table->boolean('is_emergency')->default(false);
            $table->enum('urgency', ['emergency', 'high', 'normal', 'low'])->nullable();

            $table->enum('status', [
                'submitted', 'triaged', 'assigned', 'scheduled', 'in_progress',
                'awaiting_tenant_confirmation', 'closed', 'cancelled',
            ])->default('submitted');

            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('scheduled_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 500)->nullable(); // required on force-close (BR-24)
            $table->timestamps();

            $table->index('status');
            $table->index('tenant_id');
            $table->index('is_emergency');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_requests');
    }
};
