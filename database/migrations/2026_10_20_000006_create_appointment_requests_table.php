<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The website appointment-request queue.
 *
 * A public booking creates ONE row here (status NEW) — never an appointment
 * and never a patient. The front desk converts it into a real appointment,
 * contacts the visitor, declines it or marks it as spam.
 *
 * Only the sha-256 hash of the submitter's IP is stored, never the raw IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('phone_e164');            // E.164, normalized before the write
            $table->date('preferred_date');          // clinic-local calendar day
            $table->string('preferred_time_window')->nullable();
            $table->string('service_interest')->nullable();
            $table->text('message')->nullable();
            $table->string('language', 8)->default('en');
            $table->string('status', 16)->default('NEW');  // NEW|CONTACTED|CONVERTED|DECLINED|SPAM
            $table->string('handled_by')->nullable();
            $table->dateTimeTz('handled_at')->nullable();
            $table->ulid('patient_id')->nullable();
            $table->ulid('appointment_id')->nullable()->unique();
            $table->string('ip_hash', 64);            // sha-256 hex, never the raw IP
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['status', 'created_at']);
            $table->index('phone_e164');
            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_requests');
    }
};
