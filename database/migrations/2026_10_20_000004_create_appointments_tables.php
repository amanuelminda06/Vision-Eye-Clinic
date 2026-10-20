<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appointments and follow-up recalls.
 *
 * The legacy app dropped its "appointment request" queue: the public website
 * booking form is auto-approved and inserts a real CONFIRMED appointment, so
 * no request table exists here (mirrors migration drop_appointment_requests).
 *
 * `notes` is FREE TEXT ONLY — never diagnoses or care details (no clinical
 * notes are stored anywhere in this application).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->ulid('doctor_id');
            $table->dateTimeTz('starts_at');               // UTC instants
            $table->dateTimeTz('ends_at');
            $table->ulid('service_id')->nullable();
            $table->string('status', 16)->default('SCHEDULED');
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();             // NON-CLINICAL only
            $table->string('created_from', 24)->default('STAFF'); // STAFF|WEBSITE_REQUEST
            $table->string('created_by');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['doctor_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
            $table->index('starts_at');
            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('doctor_id')->references('id')->on('staff');
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->date('due_date');                      // clinic-local calendar day
            $table->string('reason')->nullable();          // NON-CLINICAL only
            $table->string('status', 16)->default('OPEN'); // OPEN|DONE|CANCELLED
            $table->string('created_from_appointment_id')->nullable();
            $table->string('created_by');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['status', 'due_date']);
            $table->index('patient_id');
            $table->foreign('patient_id')->references('id')->on('patients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('appointments');
    }
};
