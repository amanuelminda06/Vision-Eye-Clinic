<?php

declare(strict_types=1);

namespace App\Support\Patients;

use App\Models\Appointment;
use App\Models\MessageTask;
use App\Models\Sale;

/**
 * Detail-page tab payloads, ported from src/modules/patients/tabs.ts.
 *
 * Each tab reports `{ready, count}`. `ready` is always true in the legacy app
 * (the loaders exist even before their UI); the count drives the badge shown on
 * the active non-overview tab. Overview is always 0.
 */
final class PatientTabs
{
    /** @var list<string> */
    public const TABS = ['overview', 'sales', 'appointments', 'messages', 'sick'];

    /** Accepts a `?tab=` value, falling back to the overview (legacy tabFrom). */
    public static function from(?string $value): string
    {
        return in_array($value ?? '', self::TABS, true) ? (string) $value : 'overview';
    }

    /**
     * @return array{ready: bool, count: int}
     */
    public static function load(string $tab, string $patientId): array
    {
        $count = match ($tab) {
            'sales' => Sale::where('patient_id', $patientId)->count(),
            'appointments' => Appointment::where('patient_id', $patientId)->count(),
            'messages' => MessageTask::where('patient_id', $patientId)->count(),
            'sick' => SickLeaveQueries::count($patientId),
            default => 0,
        };

        return ['ready' => true, 'count' => $count];
    }
}
