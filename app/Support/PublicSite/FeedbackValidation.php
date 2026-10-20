<?php

declare(strict_types=1);

namespace App\Support\PublicSite;

/**
 * Public feedback-form validation, ported from
 * src/modules/public-site/schemas.ts (`feedbackFormSchema`).
 */
final class FeedbackValidation
{
    public const MAX_FEEDBACK_NAME = 120;

    public const MAX_FEEDBACK_EMAIL = 254;

    public const MAX_FEEDBACK_TEXT = 1000;

    /** The email grammar used by the legacy (a practical, forgiving one). */
    private const EMAIL_RE = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    /**
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function feedbackForm(array $values): array
    {
        $fields = [];

        $name = trim(self::str($values['name'] ?? ''));
        if ($name === '') {
            $fields['name'] = 'required';
        } elseif (mb_strlen($name) > self::MAX_FEEDBACK_NAME) {
            $fields['name'] = 'tooLong';
        }

        $email = trim(self::str($values['email'] ?? ''));
        $email = $email === '' ? null : mb_strtolower($email);

        if ($email !== null) {
            if (mb_strlen($email) > self::MAX_FEEDBACK_EMAIL) {
                $fields['email'] = 'tooLong';
            } elseif (preg_match(self::EMAIL_RE, $email) !== 1) {
                $fields['email'] = 'invalidEmail';
            }
        }

        $rating = self::toRating($values['rating'] ?? null);
        if ($rating === false) {
            $fields['rating'] = 'invalidRating';
        }

        $message = trim(self::str($values['message'] ?? ''));
        if ($message === '') {
            $fields['message'] = 'required';
        } elseif (mb_strlen($message) > self::MAX_FEEDBACK_TEXT) {
            $fields['message'] = 'tooLong';
        }

        $language = trim(self::str($values['language'] ?? 'en'));
        if ($language === '') {
            $language = 'en';
        }
        if ($language !== 'en' && $language !== 'am') {
            $fields['language'] = 'invalidLanguage';
        }

        if (! self::checkbox($values['consent'] ?? false)) {
            $fields['consent'] = 'consentRequired';
        }

        $website = self::str($values['website'] ?? '');
        if ($website !== '') {
            $fields['website'] = 'spam';
        }

        $turnstileToken = self::str($values['turnstileToken'] ?? '');
        if (mb_strlen($turnstileToken) > 4096) {
            $fields['turnstileToken'] = 'tooLong';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'name' => $name,
                'email' => $email,
                'rating' => $rating === false ? null : $rating,
                'message' => $message,
                'language' => $language,
                'consent' => true,
                'website' => '',
                'turnstileToken' => $turnstileToken === '' ? null : $turnstileToken,
            ],
            'fields' => [],
        ];
    }

    /**
     * A rating is an integer 1..5, or null when absent/empty.
     *
     * @return int|null|false false = invalid
     */
    private static function toRating(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $number = $value;
        } elseif (is_float($value) && is_finite($value)) {
            $number = (int) $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $number = (int) trim($value);
        } else {
            return false;
        }

        if ($number < 1 || $number > 5) {
            return false;
        }

        return $number;
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
