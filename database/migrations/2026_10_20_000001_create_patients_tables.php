<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patients, tags and patient sick leave.
 *
 * Privacy rules encoded here (mirroring prisma/schema.prisma):
 * - phone_e164 is unique and always stored as E.164 (+2519… / +2517…).
 * - No clinical fields exist: notes_non_clinical is free text ONLY.
 * - Records are never hard-deleted: `active` is the deactivation flag.
 * - Patient consent columns were deliberately removed in the legacy app
 *   ("remove_sms_consent"): consent is collected on the public booking form,
 *   while do_not_message carries the per-patient suppression.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('full_name');
            $table->string('phone_e164', 32)->unique();
            $table->string('sex', 8)->nullable();          // MALE | FEMALE | OTHER
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('preferred_language', 8)->default('en'); // en | am
            $table->text('notes_non_clinical')->nullable();
            $table->boolean('do_not_message')->default(false);
            $table->string('do_not_message_reason')->nullable();
            $table->string('id_photo_url')->nullable();    // feature-flagged off by default
            $table->string('id_no')->nullable();
            $table->boolean('active')->default(true);
            $table->string('created_by');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index('full_name');
            $table->index('age');
            $table->index('active');
            $table->index('created_at');
            $table->index('id_no');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();              // lowercase display name
            $table->string('color', 9);                    // #3B82F6
        });

        Schema::create('patient_tag', function (Blueprint $table) {
            $table->ulid('patient_id');
            $table->ulid('tag_id');

            $table->primary(['patient_id', 'tag_id']);
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
        });

        Schema::create('patient_sick_leaves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason')->nullable();           // NON-CLINICAL only
            $table->text('note')->nullable();               // NON-CLINICAL only
            $table->ulid('recorded_by');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['patient_id', 'start_date']);
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_sick_leaves');
        Schema::dropIfExists('patient_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('patients');
    }
};
