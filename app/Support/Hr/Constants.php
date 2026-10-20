<?php

declare(strict_types=1);

namespace App\Support\Hr;

/**
 * Fixed HR constants, ported from src/modules/hr/constants.ts.
 */
final class Constants
{
    /** @var list<string> */
    public const LEAVE_TYPES = ['ANNUAL', 'SICK', 'UNPAID', 'OTHER'];

    /** @var array<string, string> */
    public const LEAVE_TYPE_LABEL_KEYS = [
        'ANNUAL' => 'typeAnnual',
        'SICK' => 'typeSick',
        'UNPAID' => 'typeUnpaid',
        'OTHER' => 'typeOther',
    ];

    /** @var list<string> */
    public const LEAVE_STATUSES = ['REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED'];

    /** @var array<string, string> */
    public const LEAVE_STATUS_LABEL_KEYS = [
        'REQUESTED' => 'statusRequested',
        'APPROVED' => 'statusApproved',
        'REJECTED' => 'statusRejected',
        'CANCELLED' => 'statusCancelled',
    ];

    /** @var list<string> */
    public const SHIFT_SOURCES = ['TEMPLATE', 'MANUAL'];

    /** @var list<string> */
    public const WEEKDAY_LABEL_KEYS = ['daySun', 'dayMon', 'dayTue', 'dayWed', 'dayThu', 'dayFri', 'daySat'];

    /** @var list<string> */
    public const SHIFT_COLORS = [
        '#2563eb',
        '#d946ef',
        '#db2777',
        '#d97706',
        '#7c3aed',
        '#0891b2',
        '#65a30d',
        '#dc2626',
        '#4f46e5',
        '#059669',
    ];

    public static function colorForIndex(int $index): string
    {
        $count = count(self::SHIFT_COLORS);

        return self::SHIFT_COLORS[(($index % $count) + $count) % $count];
    }

    public static function isLeaveType(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::LEAVE_TYPES, true);
    }

    public static function isLeaveStatus(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::LEAVE_STATUSES, true);
    }

    public static function isShiftSource(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::SHIFT_SOURCES, true);
    }

    public const MAX_NOTE = 200;

    public const MAX_REASON = 300;

    public const MAX_DECISION_NOTE = 300;

    public const MAX_RANGE_DAYS = 62;

    public const MAX_TEMPLATE_SHIFTS = 400;

    public const MAX_TEMPLATE_STAFF = 50;

    public const TIME_RE = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public const DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';
}
