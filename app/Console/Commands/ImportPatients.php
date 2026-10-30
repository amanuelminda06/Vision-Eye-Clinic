<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Patient;
use App\Models\User;
use App\Support\Patients\SystemId;
use App\Support\Phone;
use Illuminate\Console\Command;

class ImportPatients extends Command
{
    protected $signature = 'patients:import-file {--dry-run : Validate without writing}';

    protected $description = 'Import patients from patients_list_20000.txt';

    public function handle(): int
    {
        $createdBy = '01m5jeeqr5bcmradnc47gde0te';

        if (! User::where('id', $createdBy)->exists()) {
            $this->error('created_by user not found: '.$createdBy);
            return self::FAILURE;
        }

        $file = base_path('patients_list_20000.txt');
        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->error('Cannot open '.$file);
            return self::FAILURE;
        }

        $header = fgetcsv($handle, 0, "\t");
        $idx = array_flip($header);

        $dryRun = (bool) $this->option('dry-run');
        $rowNumber = 1;
        $inserted = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $rowNumber++;

            $first = trim($row[$idx['FIRST NAME']] ?? '');
            $last = trim($row[$idx['LAST NAME']] ?? '');
            $fullName = trim($first.' '.$last);
            if ($fullName === '') $fullName = 'Unknown';

            $clinicId = trim($row[$idx['ID']] ?? '');
            $address = trim($row[$idx['ADDRESS']] ?? '');
            $phoneRaw = trim($row[$idx['PHONE NUMBER']] ?? '');
            $phone = $phoneRaw === '' ? null : Phone::toE164($phoneRaw);

            $ageRaw = trim($row[$idx['AGE']] ?? '');
            $age = null;
            if ($ageRaw !== '' && preg_match('/^\d{1,3}$/', $ageRaw)) {
                $a = (int) $ageRaw;
                if ($a >= 0 && $a <= 120) $age = $a;
            }

            $sexRaw = strtolower(trim($row[$idx['SEX']] ?? ''));
            $sex = null;
            if (in_array($sexRaw, ['m', 'male'])) $sex = 'MALE';
            elseif (in_array($sexRaw, ['f', 'female', 'fe'])) $sex = 'FEMALE';
            elseif (in_array($sexRaw, ['o', 'other'])) $sex = 'OTHER';

            $attrs = [
                'full_name' => $fullName,
                'phone_e164' => $phone,
                'sex' => $sex,
                'age' => $age,
                'preferred_language' => 'am',
                'active' => true,
                'created_by' => $createdBy,
                'id_no' => $clinicId !== '' ? $clinicId : null,
                'notes_non_clinical' => $address !== '' ? 'Address: '.$address : null,
            ];

            if ($dryRun) {
                $inserted++;
                if ($inserted % 1000 === 0) $this->info("dry-run row {$rowNumber}");
                continue;
            }

            try {
                Patient::create(array_merge($attrs, ['system_id' => SystemId::generateNext()]));
                $inserted++;
                if ($inserted % 1000 === 0) $this->info("inserted {$inserted}");
            } catch (\Throwable $e) {
                $this->error("FAILED at row {$rowNumber}: ".$e->getMessage());
                fclose($handle);
                return self::FAILURE;
            }
        }

        fclose($handle);
        $this->info("DONE inserted={$inserted} skipped={$skipped}".($dryRun ? ' (dry run)' : ''));

        return self::SUCCESS;
    }
}
