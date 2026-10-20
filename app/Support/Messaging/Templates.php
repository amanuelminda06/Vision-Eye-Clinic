<?php

declare(strict_types=1);

namespace App\Support\Messaging;

/**
 * Pure template rules: variable validation, rendering, and the SMS character
 * counter (GSM-7 vs Unicode). Ported from src/modules/messaging/templates.ts.
 */
final class Templates
{
    /** The only placeholders a body may contain. */
    public const VARIABLE_RE = '/\{([^{}]*)\}/';

    /** @var array<string, string> Sample data for the editor preview. */
    public const SAMPLE_DATA = [
        'name' => 'Hemnet Tesfaye',
        'date' => 'Mon 12 Oct',
        'time' => '10:00',
        'doctor' => 'Dr. Abebe Kebede',
        'clinic' => 'Vision Eye Clinic',
        'review_link' => 'https://g.page/vision-eye-clinic/review',
        'reopen_date' => 'Mon 19 Oct',
    ];

    /**
     * Clinical/financial terms that must not appear in a message (no
     * diagnoses, treatments, doctor specialties, or prices).
     *
     * @var list<string>
     */
    public const SENSITIVE_TERMS = [
        'diagnosis', 'diagnosed', 'treatment', 'prescription', 'medicine',
        'medication', 'surgery', 'cataract', 'glaucoma', 'price', 'birr',
        'etb', 'discount', 'ምርመራ', 'ህክምና', 'መድኃኒት', 'ቀዶ', 'ዋጋ', 'ብር',
    ];

    /**
     * Variable names referenced in a body, in order, de-duplicated.
     *
     * @return list<string>
     */
    public static function templateVariables(string $body): array
    {
        $found = [];

        if (preg_match_all(self::VARIABLE_RE, $body, $matches) === false) {
            return [];
        }

        foreach ($matches[1] as $name) {
            if (! in_array($name, $found, true)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Unknown `{variable}` placeholders — an empty array means the body is valid.
     *
     * @return list<string>
     */
    public static function unknownVariables(string $body): array
    {
        return array_values(array_filter(
            self::templateVariables($body),
            fn (string $name): bool => ! in_array($name, Constants::TEMPLATE_VARIABLES, true),
        ));
    }

    /**
     * Whether the body uses only allowed variables. A stray `{`/`}` (GSM-7
     * extension chars) is allowed as literal text.
     *
     * @return array{ok: bool, unknown: list<string>}
     */
    public static function validateTemplateBody(string $body): array
    {
        $unknown = self::unknownVariables($body);

        return ['ok' => $unknown === [], 'unknown' => $unknown];
    }

    /**
     * Substitutes known variables. When `review_link` is empty and the body
     * references it, the whole line is dropped.
     *
     * @param  array<string, string|null>  $data
     */
    public static function renderTemplate(string $body, array $data): string
    {
        $reviewEmpty = ! isset($data['review_link']) || $data['review_link'] === '';

        $lines = array_filter(
            explode("\n", $body),
            fn (string $line): bool => ! ($reviewEmpty && str_contains($line, '{review_link}')),
        );

        $text = implode("\n", $lines);

        return (string) preg_replace_callback(
            self::VARIABLE_RE,
            function (array $match) use ($data): string {
                $name = $match[1];

                if (! in_array($name, Constants::TEMPLATE_VARIABLES, true)) {
                    return '';
                }

                return $data[$name] ?? '';
            },
            $text,
        );
    }

    /**
     * Counts a message the way carrier handsets do: GSM-7 (160/153) for the
     * Latin/symbol set, Unicode (70/67) for anything else (e.g. Amharic).
     *
     * @return array{encoding: string, length: int, perPart: int, parts: int}
     */
    public static function countSms(string $text): array
    {
        $basic = array_flip(self::charSet(Constants::GSM7_BASIC));
        $extension = array_flip(self::charSet(Constants::GSM7_EXTENSION));

        $encoding = 'GSM-7';
        $length = 0;

        // Code points, not UTF-16 units (the legacy used [...text]).
        foreach (self::codePoints($text) as $char) {
            if (isset($basic[$char])) {
                $length += 1;
            } elseif (isset($extension[$char])) {
                $length += 2;
            } else {
                $encoding = 'Unicode';
                break;
            }
        }

        if ($encoding === 'Unicode') {
            $length = count(self::codePoints($text));
        }

        $perPart = $encoding === 'GSM-7' ? 160 : 70;
        $multipartPerPart = $encoding === 'GSM-7' ? 153 : 67;

        $parts = $length === 0 ? 1 : ($length <= $perPart ? 1 : (int) ceil($length / $multipartPerPart));

        return [
            'encoding' => $encoding,
            'length' => $length,
            'perPart' => $parts === 1 ? $perPart : $multipartPerPart,
            'parts' => $parts,
        ];
    }

    /**
     * Renders a body with sample data and counts the result.
     *
     * @param  array<string, string|null>  $sample
     * @return array{text: string, count: array<string, mixed>, unknown: list<string>}
     */
    public static function previewTemplate(string $body, array $sample = []): array
    {
        $merged = array_merge(self::SAMPLE_DATA, $sample);
        $text = self::renderTemplate($body, $merged);

        return [
            'text' => $text,
            'count' => self::countSms($text),
            'unknown' => self::unknownVariables($body),
        ];
    }

    /**
     * Returns the sensitive terms found in a body (empty = clean).
     *
     * @return list<string>
     */
    public static function scanSensitiveContent(string $body): array
    {
        $lower = mb_strtolower($body);
        $hits = [];

        foreach (self::SENSITIVE_TERMS as $term) {
            if (str_contains($lower, mb_strtolower($term))) {
                $hits[] = $term;
            }
        }

        return $hits;
    }

    /**
     * Validates a composer/library/picker message body: non-empty after trim,
     * within the length cap, and using only known variables. Sensitive terms
     * are reported but do not block.
     *
     * @return array{ok: bool, error: string|null, unknown: list<string>, sensitive: list<string>}
     */
    public static function validateMessageBody(string $body): array
    {
        $trimmed = trim($body);
        $unknown = self::unknownVariables($body);
        $sensitive = self::scanSensitiveContent($body);

        if ($trimmed === '') {
            return ['ok' => false, 'error' => 'messageRequired', 'unknown' => $unknown, 'sensitive' => $sensitive];
        }

        if (mb_strlen($trimmed) > Constants::MAX_CAMPAIGN_MESSAGE) {
            return ['ok' => false, 'error' => 'messageTooLong', 'unknown' => $unknown, 'sensitive' => $sensitive];
        }

        if ($unknown !== []) {
            return ['ok' => false, 'error' => 'unknownVariable', 'unknown' => $unknown, 'sensitive' => $sensitive];
        }

        return ['ok' => true, 'error' => null, 'unknown' => $unknown, 'sensitive' => $sensitive];
    }

    /**
     * The character set as a list of single characters.
     *
     * @return list<string>
     */
    private static function charSet(string $charset): array
    {
        $chars = [];

        foreach (self::codePoints($charset) as $char) {
            $chars[] = $char;
        }

        return $chars;
    }

    /**
     * Splits a string into Unicode code points.
     *
     * @return list<string>
     */
    private static function codePoints(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return array_map(
            static fn (string $char): string => $char,
            preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );
    }
}
