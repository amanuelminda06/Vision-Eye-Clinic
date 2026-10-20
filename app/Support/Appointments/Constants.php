<?php

declare(strict_types=1);

namespace App\Support\Appointments;

/**
 * Fixed appointment/follow-up constants, ported from
 * src/modules/appointments/constants.ts.
 */
final class Constants
{
    /** @var list<string> */
    public const APPOINTMENT_STATUSES = ['SCHEDULED', 'CONFIRMED', 'COMPLETED', 'NO_SHOW', 'CANCELLED'];

    /** `__('appointments.<key>')` for each status. */
    public const APPOINTMENT_STATUS_LABEL_KEYS = [
        'SCHEDULED' => 'statusScheduled',
        'CONFIRMED' => 'statusConfirmed',
        'COMPLETED' => 'statusCompleted',
        'NO_SHOW' => 'statusNoShow',
        'CANCELLED' => 'statusCancelled',
    ];

    /** @var list<string> */
    public const APPOINTMENT_SOURCES = ['STAFF', 'WEBSITE_REQUEST'];

    /** `__('appointments.<key>')` for each source. */
    public const APPOINTMENT_SOURCE_LABEL_KEYS = [
        'STAFF' => 'sourceStaff',
        'WEBSITE_REQUEST' => 'sourceWebsite',
    ];

    /** @var list<string> */
    public const FOLLOW_UP_STATUSES = ['OPEN', 'DONE', 'CANCELLED'];

    /** `__('followups.<key>')` for each follow-up status. */
    public const FOLLOW_UP_STATUS_LABEL_KEYS = [
        'OPEN' => 'statusOpen',
        'DONE' => 'statusDone',
        'CANCELLED' => 'statusCancelled',
    ];

    /** Compact duration presets for the appointment form (minutes). */
    public const DURATIONS = [15, 30, 45, 60, 90];

    public const DEFAULT_DURATION = 30;

    /**
     * Clinic-local wall-clock slots a website request maps to.
     *
     * @var list<array{key: string, labelKey: string, start: string, end: string}>
     */
    public const TIME_WINDOWS = [
        ['key' => 'any', 'labelKey' => 'windowAny', 'start' => '09:00', 'end' => '10:00'],
        ['key' => 'morning', 'labelKey' => 'windowMorning', 'start' => '09:00', 'end' => '10:00'],
        ['key' => 'afternoon', 'labelKey' => 'windowAfternoon', 'start' => '14:00', 'end' => '15:00'],
        ['key' => 'evening', 'labelKey' => 'windowEvening', 'start' => '16:00', 'end' => '17:00'],
    ];

    /** @var list<string> Follow-up due presets. */
    public const FOLLOW_UP_PRESETS = ['2w', '1m', '3m', '6m', 'custom'];

    public const MAX_APPT_NOTES = 300;

    public const MAX_CANCEL_REASON = 300;

    public const MAX_FOLLOW_UP_REASON = 300;

    public const MAX_REQUEST_NAME = 100;

    public const MAX_REQUEST_MESSAGE = 500;

    public const MAX_REQUEST_INTEREST = 200;

    public const MAX_REQUEST_DATE_DAYS_AHEAD = 180;

    public const APPT_TIME_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public const DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';

    public static function isAppointmentStatus(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::APPOINTMENT_STATUSES, true);
    }

    public static function isAppointmentSource(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::APPOINTMENT_SOURCES, true);
    }

    public static function isFollowUpStatus(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::FOLLOW_UP_STATUSES, true);
    }

    public static function isFollowUpPreset(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::FOLLOW_UP_PRESETS, true);
    }
}
