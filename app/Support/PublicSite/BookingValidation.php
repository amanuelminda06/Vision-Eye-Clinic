<?php

declare(strict_types=1);

namespace App\Support\PublicSite;

use App\Support\Appointments\Constants;

/**
 * Public booking-form validation, ported from
 * src/modules/public-site/schemas.ts (`bookingFormSchema`).
 *
 * Field errors are `['field' => '<i18n key>']`; the public site renders
 * `site.book.errors.<key>` for the codes it recognises and falls back to the
 * generic banner for the rest.
 */
final class BookingValidation
{
    /**
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function bookingForm(array $values): array
    {
        $fields = [];

        $name = trim(self::str($values['name'] ?? ''));
        if ($name === '') {
            $fields['name'] = 'required';
        } elseif (mb_strlen($name) > Constants::MAX_REQUEST_NAME) {
            $fields['name'] = 'tooLong';
        }

        $phone = trim(self::str($values['phone'] ?? ''));
        if ($phone === '') {
            $fields['phone'] = 'required';
        } elseif (mb_strlen($phone) > 32) {
            $fields['phone'] = 'tooLong';
        }

        $preferredDate = trim(self::str($values['preferredDate'] ?? ''));
        if (preg_match(Constants::DATE_RE, $preferredDate) !== 1) {
            $fields['preferredDate'] = 'invalidDate';
        }

        $timeWindow = trim(self::str($values['preferredTimeWindow'] ?? 'any'));
        if ($timeWindow === '') {
            $timeWindow = 'any';
        }
        if (! in_array($timeWindow, ['any', 'morning', 'afternoon', 'evening'], true)) {
            $fields['preferredTimeWindow'] = 'invalidTimeWindow';
        }

        $serviceInterest = self::nullableTrimmed($values['serviceInterest'] ?? '');
        if ($serviceInterest !== null && mb_strlen($serviceInterest) > Constants::MAX_REQUEST_INTEREST) {
            $fields['serviceInterest'] = 'tooLong';
        }

        $message = self::nullableTrimmed($values['message'] ?? '');
        if ($message !== null && mb_strlen($message) > Constants::MAX_REQUEST_MESSAGE) {
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

        // The honeypot must stay empty; a filled one is treated as spam. The
        // route checks the honeypot BEFORE the schema (so a bot gets a silent
        // 200), so this only guards absurdly long values.
        $website = self::str($values['website'] ?? '');
        if (mb_strlen($website) > 0) {
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
                'phone' => $phone,
                'preferredDate' => $preferredDate,
                'preferredTimeWindow' => $timeWindow,
                'serviceInterest' => $serviceInterest,
                'message' => $message,
                'language' => $language,
                'consent' => true,
                'website' => '',
                'turnstileToken' => $turnstileToken === '' ? null : $turnstileToken,
            ],
            'fields' => [],
        ];
    }

    /** A filled honeypot means the submission is spam. */
    public static function isHoneypotTripped(mixed $value): bool
    {
        return trim(self::str($value)) !== '';
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

    private static function nullableTrimmed(mixed $value): ?string
    {
        $trimmed = trim(self::str($value));

        return $trimmed === '' ? null : $trimmed;
    }
}
