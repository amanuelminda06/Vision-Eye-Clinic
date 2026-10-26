<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patients carry an age instead of a date of birth.
 *
 * Existing birth dates are converted to an age before the column is dropped,
 * so deployed clinics keep a usable value for every patient they already have.
 * Fresh installs already create `age` in the base patients migration, so both
 * steps are guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('patients', 'age')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->unsignedTinyInteger('age')->nullable();
                $table->index('age');
            });
        }

        if (! Schema::hasColumn('patients', 'birth_date')) {
            return;
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        DB::table('patients')
            ->whereNotNull('birth_date')
            ->select(['id', 'birth_date'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($today): void {
                foreach ($rows as $row) {
                    $birthDate = DateTimeImmutable::createFromFormat(
                        '!Y-m-d',
                        substr((string) $row->birth_date, 0, 10),
                        new DateTimeZone('UTC'),
                    );

                    if ($birthDate === false || $birthDate > $today) {
                        continue;
                    }

                    DB::table('patients')->where('id', $row->id)->update([
                        'age' => $today->diff($birthDate)->y,
                    ]);
                }
            });

        $indexes = collect(Schema::getIndexes('patients'))->pluck('name');

        if ($indexes->contains('patients_birth_date_index')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->dropIndex('patients_birth_date_index');
            });
        }

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('patients', 'birth_date')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->date('birth_date')->nullable();
            });
        }

        if (Schema::hasColumn('patients', 'age')) {
            $indexes = collect(Schema::getIndexes('patients'))->pluck('name');

            if ($indexes->contains('patients_age_index')) {
                Schema::table('patients', function (Blueprint $table) {
                    $table->dropIndex('patients_age_index');
                });
            }

            Schema::table('patients', function (Blueprint $table) {
                $table->dropColumn('age');
            });
        }
    }
};
