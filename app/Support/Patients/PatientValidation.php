<?php

declare(strict_types=1);

namespace App\Support\Patients;

use App\Support\Phone;

/**
 * Patient form + sick-leave validation, ported from
 * src/modules/patients/schemas.ts and .../sick-leave/schemas.ts.
 *
 * Every rule carries an i18n KEY (e.g. 'required', 'invalidPhone'); the view
 * translates it as `validation.<key>`. The FIRST issue per field wins — the
 * order below mirrors the zod schema so error precedence is identical.
 */
final class PatientValidation
{
    public const MAX_ID_PHOTO_BYTES = 1024 * 1024;

    public const MAX_NOTES_CHARS = 2000;

    public const MIN_AGE = 0;

    public const MAX_AGE = 120;

    /** @var list<string> */
    public const SEX_VALUES = ['MALE', 'FEMALE', 'OTHER'];

    /** @var list<string> */
    public const LANGUAGE_VALUES = ['en', 'am'];

    /** @var array<string, string> */
    private const ID_PHOTO_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * Validate the create/edit patient form.
     *
     * @param  array{fullName?: string, phone?: string, sex?: string, age?: string, preferredLanguage?: string, notesNonClinical?: string, idNo?: string, idPhoto?: array{type: string, size: int}|null}  $raw
     * @return array{fields: array<string, string>, values: array<string, mixed>}
     */
    public static function patientForm(array $raw): array
    {
        $fields = [];

        $fullName = trim((string) ($raw['fullName'] ?? ''));
        if ($fullName === '') {
            $fields['fullName'] = 'required';
        } elseif (mb_strlen($fullName, 'UTF-8') > 120) {
            $fields['fullName'] = 'tooLong';
        }

        $phoneInput = trim((string) ($raw['phone'] ?? ''));
        $phone = null;
        if ($phoneInput === '') {
            $fields['phone'] = 'required';
        } else {
            $normalized = Phone::toE164($phoneInput);
            if ($normalized === null) {
                $fields['phone'] = 'invalidPhone';
            } else {
                $phone = $normalized;
            }
        }

        $sex = (string) ($raw['sex'] ?? '');
        if ($sex !== '' && ! in_array($sex, self::SEX_VALUES, true)) {
            $fields['sex'] = 'invalidSex';
        }

        $ageInput = trim((string) ($raw['age'] ?? ''));
        $age = null;
        if ($ageInput !== '') {
            if (! self::isValidAge($ageInput)) {
                $fields['age'] = 'invalidAge';
            } else {
                $age = (int) $ageInput;
            }
        }

        $language = (string) ($raw['preferredLanguage'] ?? '');
        if ($language !== '' && ! in_array($language, self::LANGUAGE_VALUES, true)) {
            $fields['preferredLanguage'] = 'invalidLanguage';
        }

        $notes = trim((string) ($raw['notesNonClinical'] ?? ''));
        if (mb_strlen($notes, 'UTF-8') > self::MAX_NOTES_CHARS) {
            $fields['notesNonClinical'] = 'tooLong';
        }

        $idNo = trim((string) ($raw['idNo'] ?? ''));
        if (mb_strlen($idNo, 'UTF-8') > 32) {
            $fields['idNo'] = 'tooLong';
        }

        $idPhoto = $raw['idPhoto'] ?? null;
        if (is_array($idPhoto)) {
            if (self::idPhotoExtension($idPhoto['type'] ?? '') === null) {
                // Legacy emitted the raw keys 'idPhotoInvalidType'/'idPhotoTooLarge',
                // which the legacy catalog never defined (a bug that printed the key
                // into the UI). The catalog's real keys are photoInvalidType /
                // photoTooLarge, so the rebuild renders human text instead.
                $fields['idPhoto'] = 'photoInvalidType';
            } elseif (($idPhoto['size'] ?? 0) > self::MAX_ID_PHOTO_BYTES) {
                $fields['idPhoto'] = 'photoTooLarge';
            }
        }

        return [
            'fields' => $fields,
            'values' => [
                'fullName' => $fullName,
                // E.164 only when valid; null otherwise so callers can bail out.
                'phone' => $phone,
                'sex' => $sex === '' ? null : $sex,
                'age' => $age,
                'preferredLanguage' => $language === '' ? null : $language,
                'notesNonClinical' => $notes === '' ? null : $notes,
                'idNo' => $idNo === '' ? null : $idNo,
            ],
        ];
    }

    /**
     * Validate a sick-leave record (cross-field: endDate >= startDate).
     *
     * @param  array{patientId?: string, startDate?: string, endDate?: string, reason?: string, note?: string}  $raw
     * @return array{fields: array<string, string>, values: array<string, mixed>}
     */
    public static function sickLeave(array $raw): array
    {
        $fields = [];

        $patientId = trim((string) ($raw['patientId'] ?? ''));
        if ($patientId === '') {
            $fields['patientId'] = 'required';
        } elseif (mb_strlen($patientId, 'UTF-8') > 64) {
            $fields['patientId'] = 'tooLong';
        }

        $startDate = trim((string) ($raw['startDate'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) {
            $fields['startDate'] = 'invalidDate';
        }

        $endDate = trim((string) ($raw['endDate'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1) {
            $fields['endDate'] = 'invalidDate';
        } elseif (! isset($fields['startDate']) && $endDate < $startDate) {
            // Only reached when both dates parsed; matches zod's refine order.
            $fields['endDate'] = 'endBeforeStart';
        }

        $reason = trim((string) ($raw['reason'] ?? ''));
        if (mb_strlen($reason, 'UTF-8') > 200) {
            $fields['reason'] = 'tooLong';
        }

        $note = trim((string) ($raw['note'] ?? ''));
        if (mb_strlen($note, 'UTF-8') > self::MAX_NOTES_CHARS) {
            $fields['note'] = 'tooLong';
        }

        return [
            'fields' => $fields,
            'values' => [
                'patientId' => $patientId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'reason' => $reason === '' ? null : $reason,
                'note' => $note === '' ? null : $note,
            ],
        ];
    }

    /** File extension for an accepted ID photo MIME type, or null when not allowed. */
    public static function idPhotoExtension(string $mimeType): ?string
    {
        return self::ID_PHOTO_EXTENSIONS[$mimeType] ?? null;
    }

    /** Today's UTC calendar date, `YYYY-MM-DD`. */
    public static function todayIso(): string
    {
        return gmdate('Y-m-d');
    }

    private static function isValidAge(string $value): bool
    {
        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return false;
        }

        return (int) $value >= self::MIN_AGE && (int) $value <= self::MAX_AGE;
    }
}
