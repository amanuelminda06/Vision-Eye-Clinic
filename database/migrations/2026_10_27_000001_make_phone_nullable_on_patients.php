<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy card-registration archive contains thousands of name-only
 * registrations with no phone number. `phone_e164` must therefore accept
 * NULL so those records can be imported faithfully.
 *
 * The unique index stays: both MySQL 8.0 and SQLite treat NULLs as distinct,
 * so any number of phone-less rows is allowed while real phones remain unique.
 *
 * New patients created through the UI still require a phone — the rule lives
 * in App\Support\Patients\PatientValidation, not in the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('phone_e164', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('phone_e164', 32)->nullable(false)->change();
        });
    }
};
