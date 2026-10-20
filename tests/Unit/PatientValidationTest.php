<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Patients\PatientValidation;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/modules/patients/schemas.ts + .../sick-leave/schemas.ts
 * (patientFormSchema / patientSickLeaveSchema) and the photo-type rules.
 *
 * Every emitted error must be a real catalog key so the UI can translate it —
 * that is asserted alongside the rule behaviour.
 */
class PatientValidationTest extends TestCase
{
    /** Keys the validation catalog must define for these forms. */
    private const TRANSLATABLE = [
        'required', 'tooLong', 'invalidPhone', 'invalidSex', 'invalidDate',
        'invalidLanguage', 'photoInvalidType', 'photoTooLarge',
        'endBeforeStart', 'patientRequired',
    ];

    public function test_accepts_a_minimal_valid_form(): void
    {
        $result = PatientValidation::patientForm(['fullName' => 'Almaz Tesfaye', 'phone' => '0911223344']);

        $this->assertSame([], $result['fields']);
        $this->assertSame('Almaz Tesfaye', $result['values']['fullName']);
        $this->assertSame('+251911223344', $result['values']['phone']);
        $this->assertNull($result['values']['sex']);
        $this->assertNull($result['values']['birthDate']);
        $this->assertNull($result['values']['notesNonClinical']);
    }

    public function test_requires_name_and_phone(): void
    {
        $result = PatientValidation::patientForm(['fullName' => '  ', 'phone' => '']);

        $this->assertSame('required', $result['fields']['fullName']);
        $this->assertSame('required', $result['fields']['phone']);
    }

    public function test_rejects_too_long_name(): void
    {
        $result = PatientValidation::patientForm(['fullName' => str_repeat('a', 121), 'phone' => '0911223344']);

        $this->assertSame('tooLong', $result['fields']['fullName']);
    }

    public function test_rejects_invalid_phone(): void
    {
        $result = PatientValidation::patientForm(['fullName' => 'Almaz', 'phone' => '12345']);

        $this->assertSame('invalidPhone', $result['fields']['phone']);
        $this->assertNull($result['values']['phone']);
    }

    public function test_rejects_unknown_sex(): void
    {
        $result = PatientValidation::patientForm(['fullName' => 'Almaz', 'phone' => '0911223344', 'sex' => 'X']);

        $this->assertSame('invalidSex', $result['fields']['sex']);
    }

    public function test_accepts_each_valid_sex(): void
    {
        foreach (['MALE', 'FEMALE', 'OTHER'] as $sex) {
            $result = PatientValidation::patientForm(['fullName' => 'Almaz', 'phone' => '0911223344', 'sex' => $sex]);
            $this->assertSame([], $result['fields'], "sex {$sex} should be accepted");
        }
    }

    public function test_rejects_malformed_and_future_birth_dates(): void
    {
        $bad = PatientValidation::patientForm(['fullName' => 'A', 'phone' => '0911223344', 'birthDate' => '12/31/1990']);
        $this->assertSame('invalidDate', $bad['fields']['birthDate']);

        $impossible = PatientValidation::patientForm(['fullName' => 'A', 'phone' => '0911223344', 'birthDate' => '2021-02-31']);
        $this->assertSame('invalidDate', $impossible['fields']['birthDate']);

        $future = PatientValidation::patientForm([
            'fullName' => 'A', 'phone' => '0911223344',
            'birthDate' => gmdate('Y-m-d', time() + 86400),
        ]);
        $this->assertSame('invalidDate', $future['fields']['birthDate']);
    }

    public function test_rejects_unknown_language(): void
    {
        $result = PatientValidation::patientForm(['fullName' => 'A', 'phone' => '0911223344', 'preferredLanguage' => 'fr']);

        $this->assertSame('invalidLanguage', $result['fields']['preferredLanguage']);
    }

    public function test_rejects_overlong_notes_and_id_number(): void
    {
        $result = PatientValidation::patientForm([
            'fullName' => 'A', 'phone' => '0911223344',
            'notesNonClinical' => str_repeat('n', 2001),
            'idNo' => str_repeat('9', 33),
        ]);

        $this->assertSame('tooLong', $result['fields']['notesNonClinical']);
        $this->assertSame('tooLong', $result['fields']['idNo']);
    }

    public function test_accepts_a_valid_id_photo(): void
    {
        $result = PatientValidation::patientForm([
            'fullName' => 'A', 'phone' => '0911223344',
            'idPhoto' => ['type' => 'image/png', 'size' => 1024],
        ]);

        $this->assertArrayNotHasKey('idPhoto', $result['fields']);
    }

    public function test_rejects_a_bad_id_photo_type_and_size(): void
    {
        $type = PatientValidation::patientForm([
            'fullName' => 'A', 'phone' => '0911223344',
            'idPhoto' => ['type' => 'application/pdf', 'size' => 1024],
        ]);
        $this->assertSame('photoInvalidType', $type['fields']['idPhoto']);

        $size = PatientValidation::patientForm([
            'fullName' => 'A', 'phone' => '0911223344',
            'idPhoto' => ['type' => 'image/jpeg', 'size' => PatientValidation::MAX_ID_PHOTO_BYTES + 1],
        ]);
        $this->assertSame('photoTooLarge', $size['fields']['idPhoto']);
    }

    public function test_id_photo_extension_whitelist(): void
    {
        $this->assertSame('png', PatientValidation::idPhotoExtension('image/png'));
        $this->assertSame('jpg', PatientValidation::idPhotoExtension('image/jpeg'));
        $this->assertSame('webp', PatientValidation::idPhotoExtension('image/webp'));
        $this->assertNull(PatientValidation::idPhotoExtension('image/gif'));
        $this->assertNull(PatientValidation::idPhotoExtension(''));
    }

    public function test_sick_leave_accepts_a_valid_range(): void
    {
        $result = PatientValidation::sickLeave([
            'patientId' => 'pat_1',
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-05',
            'reason' => 'Fever',
            'note' => 'Non-clinical note',
        ]);

        $this->assertSame([], $result['fields']);
        $this->assertSame('2026-01-01', $result['values']['startDate']);
        $this->assertSame('2026-01-05', $result['values']['endDate']);
    }

    public function test_sick_leave_requires_patient_and_valid_dates(): void
    {
        $result = PatientValidation::sickLeave(['patientId' => '', 'startDate' => 'bad', 'endDate' => '']);

        $this->assertSame('required', $result['fields']['patientId']);
        $this->assertSame('invalidDate', $result['fields']['startDate']);
        $this->assertSame('invalidDate', $result['fields']['endDate']);
    }

    public function test_sick_leave_rejects_end_before_start(): void
    {
        $result = PatientValidation::sickLeave([
            'patientId' => 'pat_1',
            'startDate' => '2026-02-10',
            'endDate' => '2026-02-01',
        ]);

        $this->assertSame('endBeforeStart', $result['fields']['endDate']);
    }

    public function test_sick_leave_rejects_overlong_reason_and_note(): void
    {
        $result = PatientValidation::sickLeave([
            'patientId' => 'pat_1',
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-02',
            'reason' => str_repeat('r', 201),
            'note' => str_repeat('n', 2001),
        ]);

        $this->assertSame('tooLong', $result['fields']['reason']);
        $this->assertSame('tooLong', $result['fields']['note']);
    }

    public function test_every_emitted_error_key_is_translatable(): void
    {
        // Trip every rule on both forms and confirm each key exists in the
        // English validation catalog (so no raw key can reach the UI).
        $emitted = array_values(array_unique(array_merge(
            array_values(PatientValidation::patientForm([
                'fullName' => str_repeat('a', 121),
                'phone' => 'nope',
                'sex' => 'X',
                'birthDate' => 'bad',
                'preferredLanguage' => 'fr',
                'notesNonClinical' => str_repeat('n', 2001),
                'idNo' => str_repeat('9', 33),
                'idPhoto' => ['type' => 'application/pdf', 'size' => 0],
            ])['fields']),
            array_values(PatientValidation::sickLeave([
                'patientId' => '',
                'startDate' => 'bad',
                'endDate' => 'bad',
                'reason' => str_repeat('r', 201),
                'note' => str_repeat('n', 2001),
            ])['fields']),
        )));

        foreach ($emitted as $key) {
            $this->assertContains($key, self::TRANSLATABLE, "unknown error key: {$key}");
        }
    }
}
