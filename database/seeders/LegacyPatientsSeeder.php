<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\User;
use App\Support\Patients\SystemId;
use App\Support\Phone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Imports the legacy card-registration archive
 * (database/seeders/data/legacy-patients-full.csv) as starting data.
 *
 * The archive is exported verbatim from the Access file, so most rows carry a
 * name, age and sex but no phone number. Those are imported with
 * `phone_e164 = NULL` (allowed by the 2026_10_27 migration); new patients added
 * through the UI still require a phone.
 *
 * Idempotent: a row with a phone is skipped when that phone already exists; a
 * phone-less row is skipped when the same (name, age, sex) already exists.
 */
class LegacyPatientsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/legacy-patients-full.csv');
        if (! file_exists($path)) {
            $path = database_path('seeders/data/legacy-patients.csv');
        }
        if (! file_exists($path)) {
            $this->command->error('CSV not found: '.$path);

            return;
        }

        $owner = User::where('role', 'OWNER')->first() ?? User::first();
        if ($owner === null) {
            $this->command->error('No user to attribute the import to.');

            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->command->error('Unable to open CSV: '.$path);

            return;
        }

        // Header
        fgetcsv($handle, 0, ',', '"', '\\');

        // Existing keys, loaded once so the import stays linear.
        $existingPhones = [];
        foreach (Patient::whereNotNull('phone_e164')->pluck('phone_e164') as $phone) {
            $existingPhones[$phone] = true;
        }

        $existingNoPhone = [];
        foreach (Patient::whereNull('phone_e164')->get(['full_name', 'age', 'sex']) as $patient) {
            $existingNoPhone[self::noPhoneKey($patient->full_name, $patient->age, $patient->sex)] = true;
        }

        // Next system ID, advanced in memory rather than re-queried per row.
        $lastId = DB::table('patients')->whereNotNull('system_id')
            ->orderByRaw('CAST(SUBSTR(system_id,3) AS INTEGER) DESC')
            ->orderByRaw('SUBSTR(system_id,1,2) DESC')
            ->value('system_id');

        $created = 0;
        $skipped = 0;
        $invalid = 0;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || count($row) < 7) {
                $invalid++;

                continue;
            }

            $first = trim((string) ($row[1] ?? ''));
            $last = trim((string) ($row[2] ?? ''));
            $name = trim($first.' '.$last);
            if ($name === '' || $name === '-' || $name === ' -' || $name === '--') {
                $invalid++;

                continue;
            }

            $age = trim((string) ($row[4] ?? ''));
            $age = ($age !== '' && ctype_digit($age) && (int) $age <= 150) ? (int) $age : null;

            $sexRaw = strtoupper(trim((string) ($row[5] ?? '')));
            $sex = match (true) {
                in_array($sexRaw, ['M', '1', 'MALE'], true) => 'MALE',
                in_array($sexRaw, ['F', 'FE', 'FEMALE'], true) => 'FEMALE',
                default => null,
            };

            $phoneE164 = self::normalizePhone((string) ($row[6] ?? ''));

            $key = $phoneE164 !== null ? 'p:'.$phoneE164 : self::noPhoneKey($name, $age, $sex);
            $alreadyExists = $phoneE164 !== null
                ? isset($existingPhones[$phoneE164])
                : isset($existingNoPhone[$key]);

            if ($alreadyExists) {
                $skipped++;

                continue;
            }

            $lastId = $lastId === null ? SystemId::FIRST : SystemId::next($lastId);

            try {
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
            } catch (\Throwable $e) {
                $invalid++;

                continue;
            }

            $phoneE164 !== null
                ? $existingPhones[$phoneE164] = true
                : $existingNoPhone[$key] = true;

            $created++;
        }

        fclose($handle);

        $this->command->info("Created: {$created}, Skipped: {$skipped}, Invalid: {$invalid}");
    }

    /** Stable in-memory key for a patient that has no phone number. */
    private static function noPhoneKey(?string $name, ?int $age, ?string $sex): string
    {
        return 'n:'.mb_strtolower((string) $name).'|'.($age ?? '').'|'.($sex ?? '');
    }

    /** Normalize a legacy phone or return null when it is empty/unusable. */
    private static function normalizePhone(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $direct = Phone::toE164($raw);
        if ($direct !== null) {
            return $direct;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';

        return strlen($digits) === 9 ? Phone::toE164('0'.$digits) : null;
    }
}
