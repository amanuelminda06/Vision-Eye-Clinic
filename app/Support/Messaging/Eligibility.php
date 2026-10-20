<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Support\Phone;

/**
 * Pure eligibility check, ported from src/modules/messaging/eligibility.ts.
 *
 * The check runs both when a task is created and again when the operator
 * opens it. A failure never drops the task — it marks it BLOCKED with a
 * visible reason.
 */
final class Eligibility
{
    /**
     * @param  array{patient: array{active: bool, doNotMessage: bool, phoneE164: string}, sourceStatus?: string|null}  $input
     */
    public static function blockedReason(array $input): ?string
    {
        $patient = $input['patient'];

        if (! $patient['active']) {
            return 'inactive';
        }

        if ($patient['doNotMessage']) {
            return 'doNotMessage';
        }

        if (! self::isValidPhone($patient['phoneE164'])) {
            return 'invalidPhone';
        }

        if (($input['sourceStatus'] ?? null) === 'CANCELLED') {
            return 'sourceCancelled';
        }

        return null;
    }

    /**
     * @param  array{patient: array{active: bool, doNotMessage: bool, phoneE164: string}, sourceStatus?: string|null}  $input
     */
    public static function isEligible(array $input): bool
    {
        return self::blockedReason($input) === null;
    }

    /** A valid E.164 Ethiopian mobile number. */
    public static function isValidPhone(?string $phone): bool
    {
        return $phone !== null && Phone::toE164($phone) !== null;
    }
}
