<?php

declare(strict_types=1);

namespace App\Support\Shell;

use App\Models\Staff;
use App\Support\Phone;
use App\Support\Roles;

/**
 * User + staff form validation, ported from
 * src/modules/users/schemas.ts and src/modules/staff/schemas.ts.
 *
 * Field errors are `['field' => '<i18n key>']`; the view renders
 * `validation.<key>`.
 */
final class ShellValidation
{
    public const MAX_PHOTO_BYTES = 1024 * 1024;

    /** @var array<string, string> */
    public const PHOTO_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * Validate the user create/update form.
     *
     * @param  array<string, mixed>  $values
     * @param  bool  $withPassword  the create form carries a password
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function userForm(array $values, bool $withPassword): array
    {
        $fields = [];

        $name = trim(self::str($values['name'] ?? ''));
        if ($name === '') {
            $fields['name'] = 'required';
        } elseif (mb_strlen($name) > 100) {
            $fields['name'] = 'tooLong';
        }

        $email = self::email($values['email'] ?? '');
        if ($email === null) {
            $fields['email'] = self::emailError($values['email'] ?? '');
        }

        $role = self::str($values['role'] ?? '');
        if (! Roles::isRole($role)) {
            $fields['role'] = 'invalid';
        }

        $staffId = trim(self::str($values['staffId'] ?? ''));
        if (mb_strlen($staffId) > 64) {
            $fields['staffId'] = 'tooLong';
        } elseif ($staffId !== '' && Staff::find($staffId) === null) {
            $fields['staffId'] = 'unknown';
        }

        if ($withPassword) {
            $password = (string) ($values['password'] ?? '');
            if ($password === '') {
                $fields['password'] = 'required';
            }
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'name' => $name,
                'email' => (string) $email,
                'role' => $role,
                'staffId' => $staffId === '' ? null : $staffId,
                'active' => (string) ($values['active'] ?? 'true') !== 'false',
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the "set a password" form.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function setPassword(array $values): array
    {
        $fields = [];

        $password = (string) ($values['password'] ?? '');
        $confirm = (string) ($values['confirmPassword'] ?? '');

        if ($password === '') {
            $fields['password'] = 'required';
        }

        if ($confirm === '') {
            $fields['confirmPassword'] = 'required';
        } elseif ($password !== '' && $password !== $confirm) {
            $fields['confirmPassword'] = 'passwordMismatch';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'password' => $password,
                'mustChangePassword' => (string) ($values['mustChangePassword'] ?? 'false') === 'true',
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the staff form.
     *
     * @param  array<string, mixed>  $values
     * @param  array{mimeType: string, size: int}|null  $photo
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function staffForm(array $values, ?array $photo = null): array
    {
        $fields = [];

        $fullName = trim(self::str($values['fullName'] ?? ''));
        if ($fullName === '') {
            $fields['fullName'] = 'required';
        } elseif (mb_strlen($fullName) > 100) {
            $fields['fullName'] = 'tooLong';
        }

        $jobTitle = trim(self::str($values['jobTitle'] ?? ''));
        if ($jobTitle === '') {
            $fields['jobTitle'] = 'required';
        } elseif (mb_strlen($jobTitle) > 100) {
            $fields['jobTitle'] = 'tooLong';
        }

        $specialty = trim(self::str($values['specialty'] ?? ''));
        if (mb_strlen($specialty) > 100) {
            $fields['specialty'] = 'tooLong';
        }

        $phoneRaw = trim(self::str($values['phone'] ?? ''));
        $phone = null;
        if ($phoneRaw !== '') {
            $phone = Phone::toE164($phoneRaw);
            if ($phone === null) {
                $fields['phone'] = 'invalidPhone';
            }
        }

        if ($photo !== null) {
            if (! array_key_exists($photo['mimeType'], self::PHOTO_EXTENSIONS)) {
                $fields['photo'] = 'photoInvalidType';
            } elseif ($photo['size'] > self::MAX_PHOTO_BYTES) {
                $fields['photo'] = 'photoTooLarge';
            }
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'fullName' => $fullName,
                'jobTitle' => $jobTitle,
                'isDoctor' => self::checkbox($values['isDoctor'] ?? false),
                'specialty' => $specialty === '' ? null : $specialty,
                'phone' => $phone,
                'showOnWebsite' => self::checkbox($values['showOnWebsite'] ?? false),
                'active' => (string) ($values['active'] ?? 'true') !== 'false',
            ],
            'fields' => [],
        ];
    }

    /** The photo extension for a MIME type (null when not allowed). */
    public static function photoExtension(?string $mimeType): ?string
    {
        if ($mimeType === null) {
            return null;
        }

        return self::PHOTO_EXTENSIONS[$mimeType] ?? null;
    }

    /**
     * A trimmed, lowercased email (null when invalid).
     */
    public static function email(mixed $raw): ?string
    {
        $email = mb_strtolower(trim(self::str($raw)));

        if ($email === '' || mb_strlen($email) > 254) {
            return null;
        }

        if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            return null;
        }

        return $email;
    }

    /** The error key for a rejected email. */
    public static function emailError(mixed $raw): string
    {
        $email = trim(self::str($raw));

        if ($email === '') {
            return 'required';
        }

        if (mb_strlen($email) > 254) {
            return 'tooLong';
        }

        return 'invalidEmail';
    }

    private static function checkbox(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
