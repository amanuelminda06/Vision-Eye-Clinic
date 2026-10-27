<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\User;
use App\Support\Patients\SystemId;
use App\Support\Phone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LegacyPatientsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/legacy-patients-full.csv');
        if (! file_exists($path)) {
            $path = database_path('seeders/data/legacy-patients.csv');
        }
        if (! file_exists($path)) {
            $this->command->error('CSV not found');

            return;
        }

        $rows = array_map('str_getcsv', file($path));
        $header = array_shift($rows);

        $owner = User::where('role', 'OWNER')->first() ?? User::first();

        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (count($row) < 8) {
                continue;
            }

            $first = trim($row[1] ?? '');
            $last = trim($row[2] ?? '');
            $name = trim($first.' '.$last);
            if ($name === '' || $name === '-' || $name === ' -') {
                continue;
            }

            $age = $row[4] ?? null;
            if ($age === '' || $age === null) {
                $age = null;
            } elseif (is_numeric($age)) {
                $age = (int) $age;
                if ($age < 0 || $age > 150) {
                    $age = null;
                }
            } else {
                $age = null;
            }

            $sex = trim($row[5] ?? '');
            $sexUpper = strtoupper($sex);
            if ($sexUpper === 'M' || $sex === '1') {
                $sex = 'MALE';
            } elseif ($sexUpper === 'F' || $sexUpper === 'FE' || $sexUpper === 'FEMALE') {
                $sex = 'FEMALE';
            } else {
                $sex = null;
            }

            $phone = trim($row[6] ?? '');
            if ($phone === '' || $phone === null) {
                continue;
            }

            $phoneE164 = Phone::toE164($phone);
            if (! $phoneE164) {
                $digits = preg_replace('/\D/', '', $phone);
                if (strlen($digits) === 9) {
                    $phoneE164 = Phone::toE164('0'.$digits);
                }
            }
            if (! $phoneE164) {
                continue;
            }

            if (Patient::where('phone_e164', $phoneE164)->exists()) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($name, $phoneE164, $age, $sex, $owner, &$created) {
                    $lastId = DB::table('patients')->whereNotNull('system_id')
                        ->orderByRaw('CAST(SUBSTR(system_id,3) AS INTEGER) DESC')
                        ->orderByRaw('SUBSTR(system_id,1,2) DESC')
                        ->value('system_id');

                    if (! $lastId) {
                        $lastId = SystemId::FIRST;
                    } else {
                        $lastId = SystemId::next($lastId);
                    }

                    Patient::create([
                        'full_name' => $name,
                        'phone_e164' => $phoneE164,
                        'sex' => $sex,
                        'age' => $age,
                        'preferred_language' => 'en',
                        'notes_non_clinical' => null,
                        'do_not_message' => false,
                        'id_photo_url' => null,
                        'id_no' => null,
                        'system_id' => $lastId,
                        'active' => true,
                        'created_by' => $owner->id,
                    ]);
                    $created++;
                });
            } catch (\Throwable $e) {
                $skipped++;

                continue;
            }
        }

        $this->command->info("Created: {$created}, Skipped: {$skipped}");
    }
}
