<?php

declare(strict_types=1);

namespace App\Support\PublicSite;

/**
 * Public projection allow-lists and the leak scanner, ported from
 * src/modules/public-site/projection.ts.
 *
 * A public payload's key set must EQUAL the sorted allow-list — extra DB
 * columns must never survive a mapper.
 */
final class Projection
{
    /**
     * Keys that must NEVER appear in a public payload (checked recursively,
     * including nested arrays).
     *
     * @var list<string>
     */
    public const FORBIDDEN_PUBLIC_FIELDS = [
        'phone', 'phoneE164', 'email', 'notes', 'notesNonClinical', 'price',
        'currentPrice', 'cost', 'salary', 'passwordHash', 'annualLeaveDays',
        'reason', 'leaveReason', 'decisionNote', 'ipHash', 'patientId',
        'staffId', 'userId',
    ];

    /**
     * The allow-lists are SORTED — a projected object's key set must equal the
     * sorted allow-list exactly.
     *
     * @var list<string>
     */
    public const PUBLIC_SERVICE_FIELDS = [
        'category', 'descriptionAm', 'descriptionEn', 'displayOrder', 'id', 'imageUrl', 'name', 'nameAm', 'nameEn',
    ];

    /** @var list<string> */
    public const PUBLIC_DOCTOR_FIELDS = [
        'id', 'name', 'nextShiftStartsAt', 'photoUrl', 'specialty', 'status', 'todayShifts',
    ];

    /** @var list<string> */
    public const PUBLIC_FEEDBACK_FIELDS = ['id', 'name', 'rating', 'text'];

    /**
     * Maps a service row to the public allow-list (the price is never
     * selected nor copied).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function toPublicService(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'nameEn' => $row['nameEn'],
            'nameAm' => $row['nameAm'],
            'category' => $row['category'],
            'displayOrder' => $row['displayOrder'],
            'imageUrl' => $row['imageUrl'],
            'descriptionEn' => $row['descriptionEn'],
            'descriptionAm' => $row['descriptionAm'],
        ];
    }

    /**
     * Maps a feedback row to the public allow-list (the email, the IP hash and
     * the moderation flag are dropped).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function toPublicFeedback(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'text' => $row['text'],
            'rating' => $row['rating'],
        ];
    }

    /**
     * The forbidden key names found anywhere in a payload.
     *
     * @return list<string>
     */
    public static function findForbiddenFields(mixed $value): array
    {
        $found = [];

        self::walk($value, $found);

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $found
     */
    private static function walk(mixed $value, array &$found): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array($key, self::FORBIDDEN_PUBLIC_FIELDS, true)) {
                    $found[] = $key;
                }

                self::walk($item, $found);
            }

            return;
        }

        if (is_object($value)) {
            self::walk((array) $value, $found);
        }
    }
}
