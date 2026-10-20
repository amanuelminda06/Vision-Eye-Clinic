<?php

declare(strict_types=1);

namespace App\Support\PublicSite;

use App\Support\ClinicTime;

/**
 * The CMS-lite public-site copy, ported from
 * src/modules/public-site/content.ts (DEFAULT_CONTENT).
 *
 * PLACEHOLDER TEXT: these defaults are safe starting copy, not the clinic's
 * real wording — the clinic must review/replace them.
 */
final class Content
{
    /** @var list<string> The content keys (in the legacy order). */
    public const CONTENT_KEYS = [
        'hero_title',
        'hero_subtitle',
        'hero_promise',
        'home_intro',
        'footer_note',
        'privacy_body',
        'announcement_text',
        'announcement_active',
        'announcement_start',
        'announcement_end',
        'faq_items',
        'testimonials_items',
        'contact_address',
        'contact_phone_primary',
        'contact_phone_backup',
        'contact_map_url',
        'social_tiktok',
        'social_instagram',
        'social_telegram',
        'doctors_intro',
        'announcements_items',
        'city',
        'country',
        'specialty',
        'og_title',
        'og_subtitle',
        'og_city',
        'map_lat',
        'map_lng',
        'map_zoom',
    ];

    /**
     * The default bilingual copy for every key.
     *
     * @var array<string, array{en: string, am: string}>
     */
    public const DEFAULT_CONTENT = [
        'hero_title' => [
            'en' => 'Clear vision for every stage of life',
            'am' => 'ለሁሉም የህይወት ደረጃ ግልጽ እይታ',
        ],
        'hero_subtitle' => [
            'en' => 'Comprehensive eye care for your whole family — from routine exams to specialist treatment.',
            'am' => 'ለመላው ቤተሰብዎ ሙሉ የዓይን ህክምና — ከመደበኛ ምርመራ እስከ ልዩ ባለሙያ ህክምና።',
        ],
        'hero_promise' => [
            'en' => 'Trusted, patient-first eye care in Addis Ababa.',
            'am' => 'በአዲስ አበባ የሚታመን፣ ታካሚን ያማከለ የዓይን ህክምና።',
        ],
        'home_intro' => [
            'en' => 'Our experienced ophthalmologists and optometrists provide thorough eye examinations, accurate diagnoses and clear guidance. We take the time to explain every step.',
            'am' => 'ልምድ ያላቸው የዓይን ሐኪሞቻችን እና የዓይን ብርጭቆ ባለሙያዎቻችን ጥልቅ ምርመራ፣ ትክክለኛ ምርመራ ውጤት እና ግልጽ ምክር ይሰጣሉ።',
        ],
        'footer_note' => [
            'en' => 'This website is for general information only and does not replace a medical consultation.',
            'am' => 'ይህ ድህረ ገጽ ለአጠቃላይ መረጃ ብቻ ነው፤ የህክምና ምክርን አይተካም።',
        ],
        'privacy_body' => [
            'en' => 'PLACEHOLDER — This privacy policy has not been finalised. Vision Eye Clinic collects the information you submit through the appointment request form (your name, phone number, preferred date and time) only to contact you about your request. We do not ask for or store medical details on this website. The clinic must replace this text with its reviewed privacy policy before launch.',
            'am' => 'ጊዜያዊ — ይህ የግላዊነት ፖሊሲ አልተጠናቀቀም። ቪዥን የዓይን ክሊኒክ በቀጠሮ ጥያቄ ቅጽ የሚያስገቡትን መረጃ (ስምዎን፣ ስልክ ቁጥርዎን፣ የሚፈልጉትን ቀንና ሰዓት) ስለ ጥያቄዎ ለማግኘት ብቻ ይሰበስባል። በዚህ ድህረ ገጽ የህክምና ዝርዝር አንጠይቅም አናከማችም። ክሊኒኩ ከመጀመሩ በፊት ይህን ጽሑፍ በተገመገመ የግላዊነት ፖሊሲ መተካት አለበት።',
        ],
        'announcement_text' => [
            'en' => '',
            'am' => '',
        ],
        'announcement_active' => [
            'en' => 'true',
            'am' => 'true',
        ],
        'announcement_start' => [
            'en' => '',
            'am' => '',
        ],
        'announcement_end' => [
            'en' => '',
            'am' => '',
        ],
        'faq_items' => [
            'en' => '',
            'am' => '',
        ],
        'testimonials_items' => [
            'en' => '',
            'am' => '',
        ],
        'contact_address' => [
            'en' => 'Kaliti Farid Building, 2nd floor, Addis Ababa, Ethiopia',
            'am' => '',
        ],
        'contact_phone_primary' => [
            'en' => '+251900000000',
            'am' => '+251900000000',
        ],
        'contact_phone_backup' => [
            'en' => '',
            'am' => '',
        ],
        'contact_map_url' => [
            'en' => 'https://maps.app.goo.gl/Cyye5QKD3qjUWVXA6',
            'am' => 'https://maps.app.goo.gl/Cyye5QKD3qjUWVXA6',
        ],
        'social_tiktok' => [
            'en' => 'https://tiktok.com/@visioneyeclinic',
            'am' => 'https://tiktok.com/@visioneyeclinic',
        ],
        'social_instagram' => [
            'en' => 'https://instagram.com/visioneyeclinic',
            'am' => 'https://instagram.com/visioneyeclinic',
        ],
        'social_telegram' => [
            'en' => 'https://t.me/visioneyeclinic',
            'am' => 'https://t.me/visioneyeclinic',
        ],
        'doctors_intro' => [
            'en' => 'Meet the team behind your bright view — our ophthalmologists and optometrists combine modern equipment with attentive, personal care.',
            'am' => 'PLACEHOLDER — Amharic doctors intro pending native-speaker review.',
        ],
        'announcements_items' => [
            'en' => '',
            'am' => '',
        ],
        'city' => [
            'en' => 'Addis Ababa',
            'am' => 'PLACEHOLDER — city',
        ],
        'country' => [
            'en' => 'Ethiopia',
            'am' => 'PLACEHOLDER — country',
        ],
        'specialty' => [
            'en' => 'Ophthalmology',
            'am' => 'PLACEHOLDER — medical specialty',
        ],
        'og_title' => [
            'en' => 'Clear vision for every stage of life',
            'am' => 'PLACEHOLDER — open-graph title',
        ],
        'og_subtitle' => [
            'en' => 'Eye examinations · Glasses · Diagnostics · Specialist care',
            'am' => 'PLACEHOLDER — open-graph subtitle',
        ],
        'og_city' => [
            'en' => 'Addis Ababa, Ethiopia',
            'am' => 'PLACEHOLDER — open-graph city',
        ],
        'map_lat' => [
            'en' => '8.896235398615133',
            'am' => '8.896235398615133',
        ],
        'map_lng' => [
            'en' => '38.77100409325467',
            'am' => '38.77100409325467',
        ],
        'map_zoom' => [
            'en' => '15',
            'am' => '15',
        ],
    ];

    /**
     * Merges DB rows (partial) over the placeholder defaults.
     *
     * A blank Amharic column is backfilled with the English value at resolve
     * time.
     *
     * @param  list<array{key: string, valueEn: string, valueAm: string}>  $rows
     * @return array<string, array{en: string, am: string}>
     */
    public static function resolveContent(array $rows): array
    {
        $map = self::DEFAULT_CONTENT;

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        foreach (self::CONTENT_KEYS as $key) {
            $row = $byKey[$key] ?? null;

            if ($row !== null) {
                $map[$key] = [
                    'en' => $row['valueEn'],
                    'am' => $row['valueAm'] !== '' ? $row['valueAm'] : $row['valueEn'],
                ];
            }
        }

        return $map;
    }

    /**
     * Picks a locale value, falling back to English when the Amharic text is
     * blank.
     *
     * @param  array{en: string, am: string}  $value
     */
    public static function pick(string $locale, array $value): string
    {
        if ($locale === 'am') {
            return $value['am'] !== '' ? $value['am'] : $value['en'];
        }

        return $value['en'];
    }

    /**
     * Parses a FAQ/testimonial/announcement JSON column defensively (never
     * throws).
     *
     * @param  callable(mixed): bool  $validate
     * @return list<array<string, mixed>>
     */
    public static function parseItems(string $json, callable $validate): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        $items = [];

        foreach ($decoded as $item) {
            if ($validate($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** A FAQ item needs a string question AND a string answer. */
    public static function isFaqItem(mixed $value): bool
    {
        return is_array($value)
            && isset($value['question'], $value['answer'])
            && is_string($value['question'])
            && is_string($value['answer']);
    }

    /** A testimonial item needs a string name AND a string text. */
    public static function isTestimonialItem(mixed $value): bool
    {
        return is_array($value)
            && isset($value['name'], $value['text'])
            && is_string($value['name'])
            && is_string($value['text']);
    }

    /** An announcement item needs a string title AND a string message. */
    public static function isAnnouncementItem(mixed $value): bool
    {
        return is_array($value)
            && isset($value['title'], $value['message'])
            && is_string($value['title'])
            && is_string($value['message']);
    }

    /**
     * The announcement banner is visible when the flag is truthy, the text is
     * not blank in EITHER language, and today is inside the inclusive window.
     *
     * The window is read from the `en` columns only (a single global window).
     *
     * @param  array<string, array{en: string, am: string}>  $content
     */
    public static function isAnnouncementVisible(array $content, \DateTimeInterface $now, string $timeZone): bool
    {
        if (! self::truthy($content['announcement_active']['en'] ?? '')) {
            return false;
        }

        if (trim($content['announcement_text']['en'] ?? '') === ''
            || trim($content['announcement_text']['am'] ?? '') === '') {
            return false;
        }

        $today = ClinicTime::clinicDateKey($now, $timeZone);
        $start = trim($content['announcement_start']['en'] ?? '');
        $end = trim($content['announcement_end']['en'] ?? '');

        if ($start !== '' && $today < $start) {
            return false;
        }

        if ($end !== '' && $today > $end) {
            return false;
        }

        return true;
    }

    /** Truthy only for the exact strings 'true', '1' and 'yes'. */
    public static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
    }
}
