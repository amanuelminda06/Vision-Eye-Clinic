<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Support\Services\Categories;

/**
 * The richer (Slice 6B) recipient filter and its pure matching rules,
 * ported from src/modules/messaging/recipient-filter.ts.
 */
final class RecipientFilter
{
    /** @var list<string> */
    public const RECIPIENT_PRESET_RANGES = ['', 'last30', 'last90', 'last180', 'thisYear'];

    /**
     * The exact zero shape.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return [
            'tags' => [],
            'tagMode' => 'ANY',
            'serviceIds' => [],
            'serviceCategory' => '',
            'language' => '',
            'lastVisitFrom' => '',
            'lastVisitTo' => '',
            'boughtServiceFrom' => '',
            'boughtServiceTo' => '',
            'upcoming' => 'ANY',
            'birthdayMonth' => 0,
            'handPickedIds' => [],
            'presetRange' => '',
        ];
    }

    /**
     * Upgrades an old (pre-6B) stored filter to the richer shape.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function normalizeLegacyFilter(array $values): array
    {
        return array_merge(self::empty(), [
            'tags' => $values['tags'] ?? [],
            'tagMode' => $values['tagMode'] ?? 'ANY',
            'language' => $values['language'] ?? '',
            'lastVisitFrom' => $values['lastVisitFrom'] ?? '',
            'lastVisitTo' => $values['lastVisitTo'] ?? '',
            'upcoming' => $values['upcoming'] ?? 'ANY',
            'serviceCategory' => $values['serviceCategory'] ?? '',
            'birthdayMonth' => $values['birthdayMonth'] ?? 0,
        ]);
    }

    /**
     * Defensive normaliser for filters read back from the DB (JSON column).
     *
     * @return array<string, mixed>
     */
    public static function normalize(mixed $input): array
    {
        $base = self::empty();

        if (! is_array($input)) {
            return $base;
        }

        $str = static fn (mixed $value, string $fallback): string => is_string($value) ? $value : $fallback;
        $strArray = static fn (mixed $value): array => is_array($value)
            ? array_values(array_filter($value, static fn ($item): bool => is_string($item)))
            : [];

        $upcoming = $input['upcoming'] ?? null;
        $preset = $input['presetRange'] ?? null;

        return [
            'tags' => $strArray($input['tags'] ?? []),
            'tagMode' => ($input['tagMode'] ?? null) === 'ALL' ? 'ALL' : 'ANY',
            'serviceIds' => $strArray($input['serviceIds'] ?? []),
            'serviceCategory' => Categories::isServiceCategory($input['serviceCategory'] ?? null)
                ? $input['serviceCategory']
                : '',
            'language' => in_array($input['language'] ?? null, ['en', 'am'], true) ? $input['language'] : '',
            'lastVisitFrom' => $str($input['lastVisitFrom'] ?? null, $base['lastVisitFrom']),
            'lastVisitTo' => $str($input['lastVisitTo'] ?? null, $base['lastVisitTo']),
            'boughtServiceFrom' => $str($input['boughtServiceFrom'] ?? null, $base['boughtServiceFrom']),
            'boughtServiceTo' => $str($input['boughtServiceTo'] ?? null, $base['boughtServiceTo']),
            'upcoming' => in_array($upcoming, ['HAS', 'NONE'], true) ? $upcoming : 'ANY',
            'birthdayMonth' => is_int($input['birthdayMonth'] ?? null) || is_float($input['birthdayMonth'] ?? null)
                ? (int) $input['birthdayMonth']
                : 0,
            'handPickedIds' => $strArray($input['handPickedIds'] ?? []),
            'presetRange' => in_array($preset, self::RECIPIENT_PRESET_RANGES, true) ? $preset : '',
        ];
    }

    /**
     * Resolves a quick preset into an explicit "last visit" date range.
     *
     * @param  array<string, mixed>  $filter
     * @return array{lastVisitFrom: string, lastVisitTo: string}
     */
    public static function resolvePresetRange(array $filter, string $todayKey): array
    {
        $preset = $filter['presetRange'] ?? '';

        if ($preset === '') {
            return [
                'lastVisitFrom' => (string) $filter['lastVisitFrom'],
                'lastVisitTo' => (string) $filter['lastVisitTo'],
            ];
        }

        if ($preset === 'thisYear') {
            return [
                'lastVisitFrom' => substr($todayKey, 0, 4).'-01-01',
                'lastVisitTo' => $todayKey,
            ];
        }

        $back = $preset === 'last30' ? 30 : ($preset === 'last90' ? 90 : 180);

        return [
            'lastVisitFrom' => self::daysBack($todayKey, $back),
            'lastVisitTo' => $todayKey,
        ];
    }

    /**
     * Whether the filter has any criteria (hand-picked patients alone don't).
     *
     * @param  array<string, mixed>  $filter
     */
    public static function hasCriteria(array $filter): bool
    {
        return ($filter['tags'] ?? []) !== []
            || ($filter['serviceIds'] ?? []) !== []
            || ($filter['serviceCategory'] ?? '') !== ''
            || ($filter['language'] ?? '') !== ''
            || ($filter['lastVisitFrom'] ?? '') !== ''
            || ($filter['lastVisitTo'] ?? '') !== ''
            || ($filter['boughtServiceFrom'] ?? '') !== ''
            || ($filter['boughtServiceTo'] ?? '') !== ''
            || ($filter['upcoming'] ?? 'ANY') !== 'ANY'
            || (int) ($filter['birthdayMonth'] ?? 0) > 0
            || ($filter['presetRange'] ?? '') !== '';
    }

    /**
     * "Plain visited between": only a last-visit date range is set — the one
     * filtered case that stays group-safe by default.
     *
     * @param  array<string, mixed>  $filter
     */
    public static function isPlainVisitedBetween(array $filter): bool
    {
        $preset = $filter['presetRange'] ?? '';

        return ($filter['tags'] ?? []) === []
            && ($filter['serviceIds'] ?? []) === []
            && ($filter['serviceCategory'] ?? '') === ''
            && ($filter['language'] ?? '') === ''
            && ($filter['boughtServiceFrom'] ?? '') === ''
            && ($filter['boughtServiceTo'] ?? '') === ''
            && ($filter['upcoming'] ?? 'ANY') === 'ANY'
            && (int) ($filter['birthdayMonth'] ?? 0) === 0
            && in_array($preset, ['', 'last30', 'last90', 'last180'], true)
            && (($filter['lastVisitFrom'] ?? '') !== '' || ($filter['lastVisitTo'] ?? '') !== '')
            && ($filter['handPickedIds'] ?? []) === [];
    }

    /**
     * Default group-mode block reasons — i18n suffixes under
     * `messages.groupBlock.*`. An empty array means the filter is group-safe.
     *
     * @param  array<string, mixed>  $filter
     * @return list<string>
     */
    public static function groupBlockReasons(array $filter): array
    {
        if (! self::hasCriteria($filter)) {
            return [];
        }

        if (self::isPlainVisitedBetween($filter)) {
            return [];
        }

        $reasons = [];

        if (($filter['serviceIds'] ?? []) !== []) {
            $reasons[] = 'service';
        }

        if (($filter['tags'] ?? []) !== []) {
            $reasons[] = 'tag';
        }

        if (($filter['serviceCategory'] ?? '') !== '') {
            $reasons[] = 'category';
        }

        if (($filter['upcoming'] ?? 'ANY') !== 'ANY') {
            $reasons[] = 'upcoming';
        }

        if (($filter['boughtServiceFrom'] ?? '') !== '' || ($filter['boughtServiceTo'] ?? '') !== '') {
            $reasons[] = 'purchase';
        }

        if ($reasons === []) {
            $reasons[] = 'filtered';
        }

        return $reasons;
    }

    /**
     * Whether group delivery is permitted (the override only covers filtered
     * cards).
     *
     * @param  array<string, mixed>  $filter
     */
    public static function groupAllowed(array $filter, bool $allowFiltered): bool
    {
        $reasons = self::groupBlockReasons($filter);

        if ($reasons === []) {
            return true;
        }

        return $allowFiltered;
    }

    /**
     * Full match: criteria matches unioned with hand-picked ids.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $filter
     */
    public static function matches(array $candidate, array $filter): bool
    {
        if (in_array($candidate['id'], $filter['handPickedIds'] ?? [], true)) {
            return true;
        }

        if (! self::hasCriteria($filter)) {
            return false;
        }

        return self::matchesCriteria($candidate, $filter);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $filter
     * @return array{eligible: list<array<string, mixed>>, preview: array<string, mixed>}
     */
    public static function summarize(array $candidates, array $filter, int $cap, bool $allowFilteredGroup): array
    {
        $filtered = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => self::matches($candidate, $filter),
        ));

        $excluded = [
            'inactive' => 0,
            'doNotMessage' => 0,
            'invalidPhone' => 0,
            'sourceCancelled' => 0,
        ];

        $eligible = [];

        foreach ($filtered as $candidate) {
            $reasons = CampaignFilter::exclusionsFor($candidate);

            if ($reasons === []) {
                $eligible[] = $candidate;

                continue;
            }

            foreach ($reasons as $reason) {
                $excluded[$reason]++;
            }
        }

        $blockReasons = self::groupBlockReasons($filter);

        return [
            'eligible' => $eligible,
            'preview' => [
                'matched' => count($filtered),
                'eligible' => count($eligible),
                'excluded' => $excluded,
                'cap' => $cap,
                'overCap' => count($eligible) > $cap,
                'groupSafe' => $blockReasons === [],
                'groupBlockReasons' => ($allowFilteredGroup && $blockReasons !== []) ? [] : $blockReasons,
            ],
        ];
    }

    /**
     * Criteria-only match (excludes the hand-picked union and hard gates).
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $filter
     */
    private static function matchesCriteria(array $candidate, array $filter): bool
    {
        $tags = $filter['tags'] ?? [];
        if ($tags !== []) {
            $lower = array_map('mb_strtolower', $candidate['tags']);
            $wanted = array_map('mb_strtolower', $tags);

            $hit = ($filter['tagMode'] ?? 'ANY') === 'ALL'
                ? array_diff($wanted, $lower) === []
                : array_intersect($wanted, $lower) !== [];

            if (! $hit) {
                return false;
            }
        }

        $serviceIds = $filter['serviceIds'] ?? [];
        if ($serviceIds !== []) {
            $bought = array_intersect($candidate['serviceIds'], $serviceIds);
            if ($bought === []) {
                return false;
            }
        }

        if (($filter['serviceCategory'] ?? '') !== ''
            && ! in_array($filter['serviceCategory'], $candidate['categories'], true)) {
            return false;
        }

        if (($filter['language'] ?? '') !== '' && $candidate['preferredLanguage'] !== $filter['language']) {
            return false;
        }

        $lastVisitFrom = $filter['lastVisitFrom'] ?? '';
        $lastVisitTo = $filter['lastVisitTo'] ?? '';

        if ($lastVisitFrom !== '' || $lastVisitTo !== '') {
            if (! self::withinRange(CampaignFilter::dateKeyOf($candidate['lastVisitAt']), $lastVisitFrom, $lastVisitTo)) {
                return false;
            }
        }

        $boughtFrom = $filter['boughtServiceFrom'] ?? '';
        $boughtTo = $filter['boughtServiceTo'] ?? '';

        if ($boughtFrom !== '' || $boughtTo !== '') {
            if (! self::withinRange(CampaignFilter::dateKeyOf($candidate['lastPurchaseAt']), $boughtFrom, $boughtTo)) {
                return false;
            }
        }

        if (($filter['upcoming'] ?? 'ANY') === 'HAS' && ! $candidate['hasUpcoming']) {
            return false;
        }

        if (($filter['upcoming'] ?? 'ANY') === 'NONE' && $candidate['hasUpcoming']) {
            return false;
        }

        if ((int) ($filter['birthdayMonth'] ?? 0) > 0) {
            if (empty($candidate['birthDate'])) {
                return false;
            }

            $month = (int) (new \DateTimeImmutable($candidate['birthDate']->format('Y-m-d').'T00:00:00Z'))->format('n');

            if ($month !== (int) $filter['birthdayMonth']) {
                return false;
            }
        }

        return true;
    }

    private static function withinRange(?string $dateKey, string $from, string $to): bool
    {
        if ($dateKey === null) {
            return false;
        }

        if ($from !== '' && $dateKey < $from) {
            return false;
        }

        if ($to !== '' && $dateKey > $to) {
            return false;
        }

        return true;
    }

    /** A date key N days before `todayKey` (pure UTC calendar arithmetic). */
    private static function daysBack(string $todayKey, int $days): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $todayKey.' 00:00:00', new \DateTimeZone('UTC'));

        if ($date === false) {
            return $todayKey;
        }

        return $date->modify(sprintf('-%d days', $days))->format('Y-m-d');
    }
}
