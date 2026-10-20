<?php

declare(strict_types=1);

namespace App\Support\PublicSite;

/**
 * Public display helpers, ported from src/modules/public-site/format.ts.
 */
final class Format
{
    /**
     * A `tel:` link, or null when the input has fewer than 6 digits.
     */
    public static function telHref(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $digits = preg_replace('/[^0-9]/', '', $cleaned) ?? '';

        if (strlen($digits) < 6) {
            return null;
        }

        return 'tel:'.$cleaned;
    }

    /**
     * A WhatsApp link, or null when there is nothing to link.
     */
    public static function whatsappHref(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        if (preg_match('#^https?://#i', $raw) === 1) {
            return $raw;
        }

        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        return 'https://wa.me/'.$digits;
    }

    /**
     * A Telegram link, or null when there is nothing to link.
     */
    public static function telegramHref(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        if (preg_match('#^https?://#i', $raw) === 1) {
            return $raw;
        }

        $handle = ltrim($raw, '@');
        $handle = preg_replace('/[^A-Za-z0-9_]/', '', $handle) ?? '';

        if ($handle === '') {
            return null;
        }

        return 'https://t.me/'.$handle;
    }

    /**
     * The "next available" label (e.g. `Mon 14:30`).
     */
    public static function formatNextAvailable(?string $iso, string $timeZone, string $locale): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        try {
            $instant = new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return '';
        }

        $local = $instant->setTimezone(new \DateTimeZone($timeZone));

        $day = $local->format('D');
        $time = $local->format('H:i');

        if ($locale === 'am') {
            $day = self::amharicWeekday((int) $local->format('w'));
        }

        return $day.' '.$time;
    }

    /**
     * The opening-hours lines, Monday first.
     *
     * @param  array<string, array{open: string, close: string, closed: bool}>  $hours
     * @param  array<string, string>  $dayLabels
     * @return list<array{day: string, value: string}>
     */
    public static function workingHoursLines(array $hours, array $dayLabels, string $closedLabel): array
    {
        $lines = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $key) {
            $day = $hours[$key] ?? null;

            $lines[] = [
                'day' => $dayLabels[$key] ?? $key,
                'value' => $day === null || $day['closed']
                    ? $closedLabel
                    : $day['open'].'–'.$day['close'],
            ];
        }

        return $lines;
    }

    /**
     * The public image URL of a service: uploaded blobs go through the narrow
     * public route, static site-relative paths render as-is.
     */
    public static function publicServiceImageUrl(string $serviceId, ?string $imageUrl): ?string
    {
        if ($imageUrl === null || $imageUrl === '') {
            return null;
        }

        if (str_starts_with($imageUrl, '/api/uploads/')) {
            return '/api/public/services/'.$serviceId.'/image';
        }

        return $imageUrl;
    }

    /** The same for a doctor photo. */
    public static function publicDoctorImageUrl(string $doctorId, ?string $photoUrl): ?string
    {
        if ($photoUrl === null || $photoUrl === '') {
            return null;
        }

        if (str_starts_with($photoUrl, '/api/uploads/')) {
            return '/api/public/doctors/'.$doctorId.'/photo';
        }

        return $photoUrl;
    }

    /** The Amharic weekday short name (0 = Sunday). */
    private static function amharicWeekday(int $weekday): string
    {
        return [
            'እሑድ', 'ሰኞ', 'ማክሰ', 'ረቡዕ', 'ሐሙስ', 'ዓርብ', 'ቅዳሜ',
        ][$weekday] ?? '';
    }
}
