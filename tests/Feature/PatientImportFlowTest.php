<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\Patients\PatientImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The CSV patient import wizard (preview → confirm), ported from
 * importPatientsAction.
 *
 * Only fully valid rows are inserted; the rest stay in the error report.
 * Confirm re-validates the echoed CSV text, so a preview cannot be tampered
 * into a bad import. Each created row writes a PATIENT_CREATED audit.
 */
class PatientImportFlowTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createUser(['role' => 'OWNER']);
    }

    private function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('patients.csv', $content);
    }

    public function test_the_import_wizard_renders(): void
    {
        $this->actingAs($this->owner())
            ->get('/patients/import')
            ->assertOk()
            ->assertSee(__('patients.importTitle'));
    }

    public function test_preview_lists_valid_and_invalid_rows_without_writing(): void
    {
        $csv = "fullName,phone,sex,age\n"
            ."Almaz Tesfaye,0911223344,FEMALE,36\n"
            ."Bad Phone,12345,MALE,36\n";

        $this->actingAs($this->owner())
            ->post('/patients/import', ['step' => 'preview', 'file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertSee(__t('patients.import.summary', ['valid' => 1, 'errors' => 1]));

        $this->assertSame(0, Patient::count());
    }

    public function test_confirm_imports_only_the_valid_rows(): void
    {
        $user = $this->owner();
        $csv = "fullName,phone,sex,age\n"
            ."Almaz Tesfaye,0911223344,FEMALE,36\n"
            ."Bad Phone,12345,MALE,36\n";

        $this->actingAs($user)
            ->post('/patients/import', ['step' => 'import', 'confirm' => 'true', 'csv' => $csv])
            ->assertOk()
            ->assertSee(__t('patients.import.done', ['imported' => 1, 'skipped' => 1]));

        $patient = Patient::sole();
        $this->assertSame('Almaz Tesfaye', $patient->full_name);
        $this->assertSame('+251911223344', $patient->phone_e164);
        $this->assertSame('FEMALE', $patient->sex);
        $this->assertTrue($patient->active);

        $log = AuditLog::where('action', 'PATIENT_CREATED')->sole();
        $this->assertSame('+251911223344', $log->new_json['phoneE164']);
        $this->assertSame([], $log->old_json);
    }

    public function test_import_applies_tags_and_the_default_language(): void
    {
        $user = $this->owner();
        Setting::create(['key' => 'default_language', 'value' => 'am']);

        $csv = "fullName,phone,tags\n"
            ."Tagged Patient,0911223355,\"glasses; cataract\"\n";

        $this->actingAs($user)
            ->post('/patients/import', ['step' => 'import', 'confirm' => 'true', 'csv' => $csv])
            ->assertOk();

        $patient = Patient::sole();
        $this->assertSame('am', $patient->preferred_language);
        $this->assertSame(['cataract', 'glasses'], $patient->tags->pluck('name')->sort()->values()->all());
        $this->assertSame(2, Tag::count());
    }

    public function test_a_row_with_an_already_taken_phone_is_a_row_error(): void
    {
        $this->createPatient(['phone_e164' => '+251911223344']);

        $csv = "fullName,phone\n"
            ."Copycat,0911223344\n";

        $this->actingAs($this->owner())
            ->post('/patients/import', ['step' => 'preview', 'file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertSee(PatientImport::IMPORT_ERROR_MESSAGES['phoneTaken']);

        $this->assertSame(1, Patient::count());
    }

    public function test_a_missing_required_column_is_rejected(): void
    {
        $csv = "fullName,sex\nOnly Name,MALE\n";

        $this->actingAs($this->owner())
            ->post('/patients/import', ['step' => 'preview', 'file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertSee(__('patients.import.errors.missingColumns'));

        $this->assertSame(0, Patient::count());
    }

    public function test_a_header_only_file_reports_empty(): void
    {
        $csv = "fullName,phone\n";

        $this->actingAs($this->owner())
            ->post('/patients/import', ['step' => 'preview', 'file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertSee(__('patients.import.errors.emptyFile'));
    }

    public function test_a_preview_without_a_file_reports_no_file(): void
    {
        $this->actingAs($this->owner())
            ->post('/patients/import', ['step' => 'preview'])
            ->assertOk()
            ->assertSee(__('patients.import.errors.noFile'));
    }

    public function test_confirm_without_the_flag_is_rejected(): void
    {
        $csv = "fullName,phone\nAlmaz,0911223344\n";

        $this->actingAs($this->owner())
            ->post('/patients/import', ['step' => 'import', 'csv' => $csv])
            ->assertOk()
            ->assertSee(__('patients.import.errors.confirmationRequired'));

        $this->assertSame(0, Patient::count());
    }

    public function test_more_than_the_row_limit_is_rejected_whole(): void
    {
        $user = $this->owner();
        $lines = ['fullName,phone'];
        for ($i = 0; $i <= PatientImport::MAX_IMPORT_ROWS; $i++) {
            $lines[] = 'Patient '.$i.',0911'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
        }
        $csv = implode("\n", $lines)."\n";

        $this->actingAs($user)
            ->post('/patients/import', ['step' => 'import', 'confirm' => 'true', 'csv' => $csv])
            ->assertOk()
            ->assertSee(__('patients.import.errors.tooManyRows'));

        $this->assertSame(0, Patient::count());
    }

    public function test_duplicate_phones_within_one_file_skip_the_second_row(): void
    {
        $csv = "fullName,phone\n"
            ."First Copy,0911223344\n"
            ."Second Copy,0911223344\n";

        $this->actingAs($this->owner())
            ->post('/patients/import', ['step' => 'import', 'confirm' => 'true', 'csv' => $csv])
            ->assertOk()
            ->assertSee(__t('patients.import.done', ['imported' => 1, 'skipped' => 1]));

        $this->assertSame(1, Patient::count());
    }

    public function test_import_writes_one_created_audit_per_row(): void
    {
        $user = $this->owner();
        $csv = "fullName,phone\nAlmaz One,0911223344\nAlmaz Two,0911223355\n";

        $this->actingAs($user)
            ->post('/patients/import', ['step' => 'import', 'confirm' => 'true', 'csv' => $csv])
            ->assertOk();

        $this->assertSame(2, AuditLog::where('action', 'PATIENT_CREATED')->count());
        $this->assertSame($user->id, AuditLog::where('action', 'PATIENT_CREATED')->first()->user_id);
    }

    public function test_staff_cannot_reach_the_import_endpoints(): void
    {
        $staff = $this->createUser(['role' => 'STAFF']);

        $this->actingAs($staff)->get('/patients/import')->assertRedirect('/403');
        $this->actingAs($staff)->post('/patients/import')->assertForbidden();
    }
}
