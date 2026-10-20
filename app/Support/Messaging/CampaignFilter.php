<?php

declare(strict_types=1);

namespace App\Support\Messaging;

/**
 * Pure campaign audience matching, ported from
 * src/modules/messaging/campaign-filter.ts.
 */
final class CampaignFilter
{
    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $filter
     */
    public static function matchesFilter(array $candidate, array $filter): bool
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

        if (($filter['language'] ?? '') !== '' && $candidate['preferredLanguage'] !== $filter['language']) {
            return false;
        }

        $lastVisitFrom = $filter['lastVisitFrom'] ?? '';
        $lastVisitTo = $filter['lastVisitTo'] ?? '';

        if ($lastVisitFrom !== '' || $lastVisitTo !== '') {
            if (empty($candidate['lastVisitAt'])) {
                return false;
            }

            $key = self::dateKeyOf($candidate['lastVisitAt']);

            if ($lastVisitFrom !== '' && $key < $lastVisitFrom) {
                return false;
            }

            if ($lastVisitTo !== '' && $key > $lastVisitTo) {
                return false;
            }
        }

        if (($filter['upcoming'] ?? 'ANY') === 'HAS' && ! $candidate['hasUpcoming']) {
            return false;
        }

        if (($filter['upcoming'] ?? 'ANY') === 'NONE' && $candidate['hasUpcoming']) {
            return false;
        }

        if (($filter['serviceCategory'] ?? '') !== ''
            && ! in_array($filter['serviceCategory'], $candidate['categories'], true)) {
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

    /**
     * Hard, non-removable gates.
     *
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    public static function exclusionsFor(array $candidate): array
    {
        $reasons = [];

        if (! $candidate['active']) {
            $reasons[] = 'inactive';
        }

        if ($candidate['doNotMessage']) {
            $reasons[] = 'doNotMessage';
        }

        if (! Eligibility::isValidPhone($candidate['phoneE164'])) {
            $reasons[] = 'invalidPhone';
        }

        return $reasons;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $filter
     * @return array{eligible: list<array<string, mixed>>, preview: array<string, mixed>}
     */
    public static function summarizeCandidates(array $candidates, array $filter, int $cap): array
    {
        $filtered = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => self::matchesFilter($candidate, $filter),
        ));

        $excluded = [
            'inactive' => 0,
            'doNotMessage' => 0,
            'invalidPhone' => 0,
            'sourceCancelled' => 0,
        ];

        $eligible = [];

        foreach ($filtered as $candidate) {
            $reasons = self::exclusionsFor($candidate);

            if ($reasons === []) {
                $eligible[] = $candidate;

                continue;
            }

            foreach ($reasons as $reason) {
                $excluded[$reason]++;
            }
        }

        return [
            'eligible' => $eligible,
            'preview' => [
                'matched' => count($filtered),
                'eligible' => count($eligible),
                'excluded' => $excluded,
                'cap' => $cap,
                'overCap' => count($eligible) > $cap,
            ],
        ];
    }

    /** The ISO date key of a date-ish value (null → null). */
    public static function dateKeyOf(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }
}
