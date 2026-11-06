<?php

declare(strict_types=1);

namespace App\Support\Patients;

use App\Models\Patient;
use App\Support\Phone;

/**
 * Family links: resolving the guardian a patient form points at, and
 * refusing the two links that would corrupt the chain.
 *
 * The form carries either an explicit patient id (`guardianId`) or a human
 * lookup typed into the `guardian` field — a phone number or a system ID
 * (AA0001). Phones are unique, so a phone resolves to exactly one patient.
 *
 * Nothing here copies a phone number: the guardian's row stays the single
 * source of truth and the child keeps their own name, age and sex.
 */
final class GuardianLink
{
    /** Bounds the chain walk (and therefore the cycle detection). */
    private const MAX_HOPS = 8;

    /** The lookup field, in input order: id first, then phone/system ID. */
    public const INPUT_KEYS = ['guardianId', 'guardian'];

    /**
     * Resolve a guardian lookup to a patient, or null when nothing matches.
     */
    public static function resolve(?string $raw): ?Patient
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        // An explicit id (deep links and programmatic posts).
        $byId = Patient::query()->find($value);
        if ($byId !== null) {
            return $byId;
        }

        // A phone number: unique, so at most one patient owns it.
        $phone = Phone::toE164($value);
        if ($phone !== null) {
            $byPhone = Patient::query()->where('phone_e164', $phone)->first();
            if ($byPhone !== null) {
                return $byPhone;
            }
        }

        // A system ID (AA0001), case-insensitive.
        $systemId = strtoupper($value);
        if (SystemId::isValid($systemId)) {
            return Patient::query()->where('system_id', $systemId)->first();
        }

        return null;
    }

    /**
     * Why `$guardian` may not be the guardian of `$patientId`, or null when
     * the link is allowed.
     *
     * Refuses the two broken shapes: a patient pointing at themselves, and a
     * loop (the guardian is — or is looked after by — someone this patient
     * already looks after). A broken chain (a guardian_id that no longer
     * resolves) is harmless and stays allowed.
     */
    public static function linkError(?string $patientId, Patient $guardian): ?string
    {
        $seen = [];
        $current = $guardian;
        $hops = 0;

        while ($current !== null && $hops < self::MAX_HOPS) {
            if ($patientId !== null && $current->id === $patientId) {
                return $current->id === $guardian->id ? 'guardianSelf' : 'guardianCycle';
            }

            if (isset($seen[$current->id])) {
                return 'guardianCycle';
            }

            $seen[$current->id] = true;

            $upId = $current->guardian_id;
            $current = ($upId === null || $upId === '')
                ? null
                : Patient::query()->find($upId);
            $hops++;
        }

        // Still climbing after the hop budget: a shape no family has, and
        // the one a loop longer than the visited set would look like.
        return $current !== null ? 'guardianCycle' : null;
    }
}
