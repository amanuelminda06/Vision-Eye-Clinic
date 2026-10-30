<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Patient;
use App\Support\Patients\SystemId;
use App\Support\Phone;

$file = __DIR__.'/patients list 20000.txt';
$handle = fopen($file, 'r');
$header = fgetcsv($handle, 0, "\t");
$idx = array_flip($header);

$sid = SystemId::generateNext();
$count = 0; $skip = 0;
while (($row = fgetcsv($handle, 0, "\t")) !== false) {
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
        $a = (int)$ageRaw;
        if ($a >= 0 && $a <= 120) $age = $a;
    }
    $sexRaw = strtolower(trim($row[$idx['SEX']] ?? ''));
    $sex = null;
    if (in_array($sexRaw, ['m','male'])) $sex='MALE';
    elseif (in_array($sexRaw, ['f','female'])) $sex='FEMALE';
    elseif (in_array($sexRaw, ['o','other'])) $sex='OTHER';

    try {
        SystemId::createPatient([
            'full_name' => $fullName,
            'phone_e164' => $phone,
            'sex' => $sex,
            'age' => $age,
            'preferred_language' => 'am',
            'active' => true,
            'created_by' => '01m5jeeqr5bcmradnc47gde0te',
            'id_no' => $clinicId !== '' ? $clinicId : null,
            'notes_non_clinical' => $address !== '' ? $address : null,
        ]);
        $count++;
        if ($count % 1000 === 0) echo "inserted $count\n";
    } catch (\Throwable $e) {
        if ($skip < 5) echo "SKIP: ".$e->getMessage()."\n";
        $skip++;
    }
}
fclose($handle);
echo "DONE inserted=$count skipped=$skip\n";
