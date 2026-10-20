<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR lite: weekly shift templates, concrete shifts and leave requests.
 *
 * - ShiftTemplate uses clinic-local wall-clock times ("HH:mm"); a template or
 *   shift may cross midnight (endTime <= startTime / endsAt <= startsAt).
 * - Shift rows are stored as UTC instants.
 * - Leave date columns are clinic-local calendar days (no time component).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('staff_id');
            $table->unsignedTinyInteger('weekday');        // 0 = Sunday … 6 = Saturday
            $table->string('start_time', 5);               // HH:mm clinic-local
            $table->string('end_time', 5);                 // may be <= start_time (overnight)
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['staff_id', 'weekday']);
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('staff_id');
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->string('source', 16)->default('MANUAL'); // TEMPLATE | MANUAL
            $table->string('note')->nullable();
            $table->string('created_by');                   // user id, no FK (users persist)
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['staff_id', 'starts_at']);
            $table->index('starts_at');
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('staff_id');
            $table->string('type', 16);                    // ANNUAL|SICK|UNPAID|OTHER
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('half_day')->default(false);
            $table->string('reason')->nullable();
            $table->string('status', 16)->default('REQUESTED');
            $table->string('decided_by')->nullable();
            $table->dateTimeTz('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->string('created_by');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['staff_id', 'start_date']);
            $table->index('status');
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_templates');
    }
};
