<?php

declare(strict_types=1);

namespace App\Support\Hr;

/**
 * HR form validation, ported from src/modules/hr/schemas.ts.
 *
 * Field errors are `['field' => '<i18n key>']`; the view renders
 * `validation.<key>`. The FIRST issue per field wins and the order below
 * mirrors the zod schema so error precedence is identical.
 */
final class HrValidation
{
    /**
     * Validate the shift create/edit form.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function shiftForm(array $values): array
    {
        $fields = [];

        $id = self::str($values['id'] ?? '');
        if ($id !== '' && mb_strlen($id) > 64) {
            $fields['id'] = 'tooLong';
        }

        $staffId = self::str($values['staffId'] ?? '');
        if ($staffId === '') {
            $fields['staffId'] = 'required';
        } elseif (mb_strlen($staffId) > 64) {
            $fields['staffId'] = 'tooLong';
        }

        $dateKey = self::str($values['dateKey'] ?? '');
        if (preg_match(Constants::DATE_RE, $dateKey) !== 1) {
            $fields['dateKey'] = 'invalidDate';
        }

        $startTime = self::str($values['startTime'] ?? '');
        if (preg_match(Constants::TIME_RE, $startTime) !== 1) {
            $fields['startTime'] = 'invalidTime';
        }

        $endTime = self::str($values['endTime'] ?? '');
        if (preg_match(Constants::TIME_RE, $endTime) !== 1) {
            $fields['endTime'] = 'invalidTime';
        }

        $note = trim(self::str($values['note'] ?? ''));
        if (mb_strlen($note) > Constants::MAX_NOTE) {
            $fields['note'] = 'tooLong';
        }

        // Refine: a zero-length shift is invalid.
        if (! isset($fields['startTime']) && ! isset($fields['endTime']) && $startTime === $endTime) {
            $fields['endTime'] = 'invalidTime';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'id' => $id === '' ? null : $id,
                'staffId' => $staffId,
                'dateKey' => $dateKey,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'note' => $note === '' ? null : $note,
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the shift-template form.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function templateForm(array $values): array
    {
        $fields = [];

        $staffId = self::str($values['staffId'] ?? '');
        if ($staffId === '') {
            $fields['staffId'] = 'required';
        } elseif (mb_strlen($staffId) > 64) {
            $fields['staffId'] = 'tooLong';
        }

        $weekday = self::toInt($values['weekday'] ?? null);
        if ($weekday === null || $weekday < 0 || $weekday > 6) {
            $fields['weekday'] = 'invalid';
        }

        $startTime = self::str($values['startTime'] ?? '');
        if (preg_match(Constants::TIME_RE, $startTime) !== 1) {
            $fields['startTime'] = 'invalidTime';
        }

        $endTime = self::str($values['endTime'] ?? '');
        if (preg_match(Constants::TIME_RE, $endTime) !== 1) {
            $fields['endTime'] = 'invalidTime';
        }

        $validFrom = self::str($values['validFrom'] ?? '');
        if (preg_match(Constants::DATE_RE, $validFrom) !== 1) {
            $fields['validFrom'] = 'invalidDate';
        }

        $validTo = self::str($values['validTo'] ?? '');
        if ($validTo !== '' && preg_match(Constants::DATE_RE, $validTo) !== 1) {
            $fields['validTo'] = 'invalidDate';
        }

        if (! isset($fields['startTime']) && ! isset($fields['endTime']) && $startTime === $endTime) {
            $fields['endTime'] = 'invalidTime';
        }

        if (! isset($fields['validFrom']) && ! isset($fields['validTo'])
            && $validTo !== '' && $validTo < $validFrom) {
            $fields['validTo'] = 'endBeforeStart';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'staffId' => $staffId,
                'weekday' => $weekday,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'validFrom' => $validFrom,
                'validTo' => $validTo === '' ? null : $validTo,
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the template preview/apply range.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function generateRange(array $values): array
    {
        $fields = [];

        $staffId = self::str($values['staffId'] ?? '');
        if (mb_strlen($staffId) > 64) {
            $fields['staffId'] = 'tooLong';
        }

        $fromKey = self::str($values['fromKey'] ?? '');
        if (preg_match(Constants::DATE_RE, $fromKey) !== 1) {
            $fields['fromKey'] = 'invalidDate';
        }

        $toKey = self::str($values['toKey'] ?? '');
        if (preg_match(Constants::DATE_RE, $toKey) !== 1) {
            $fields['toKey'] = 'invalidDate';
        }

        if (! isset($fields['fromKey']) && ! isset($fields['toKey'])) {
            $from = self::parseKey($fromKey);
            $to = self::parseKey($toKey);

            if ($from === null || $to === null || $to < $from
                || (int) (($to - $from) / 86400) + 1 > Constants::MAX_RANGE_DAYS) {
                $fields['toKey'] = 'rangeTooLong';
            }
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'staffId' => $staffId === '' ? null : $staffId,
                'fromKey' => $fromKey,
                'toKey' => $toKey,
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the leave request form.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function leaveRequest(array $values): array
    {
        $fields = [];

        $staffId = self::str($values['staffId'] ?? '');
        if (mb_strlen($staffId) > 64) {
            $fields['staffId'] = 'tooLong';
        }

        $type = self::str($values['type'] ?? '');
        if (! Constants::isLeaveType($type)) {
            $fields['type'] = 'invalidLeaveType';
        }

        $startDate = self::str($values['startDate'] ?? '');
        if (preg_match(Constants::DATE_RE, $startDate) !== 1) {
            $fields['startDate'] = 'invalidDate';
        }

        $endDate = self::str($values['endDate'] ?? '');
        if (preg_match(Constants::DATE_RE, $endDate) !== 1) {
            $fields['endDate'] = 'invalidDate';
        }

        $reason = trim(self::str($values['reason'] ?? ''));
        if (mb_strlen($reason) > Constants::MAX_REASON) {
            $fields['reason'] = 'tooLong';
        }

        if (! isset($fields['startDate']) && ! isset($fields['endDate']) && $endDate < $startDate) {
            $fields['endDate'] = 'endBeforeStart';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'staffId' => $staffId === '' ? null : $staffId,
                'type' => $type,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'halfDay' => self::checkbox($values['halfDay'] ?? false),
                'reason' => $reason === '' ? null : $reason,
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the leave decision / cancel form (identical shape).
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function leaveDecision(array $values): array
    {
        $fields = [];

        $leaveId = self::str($values['leaveId'] ?? '');
        if ($leaveId === '') {
            $fields['leaveId'] = 'required';
        } elseif (mb_strlen($leaveId) > 64) {
            $fields['leaveId'] = 'tooLong';
        }

        $note = trim(self::str($values['note'] ?? ''));
        if (mb_strlen($note) > Constants::MAX_DECISION_NOTE) {
            $fields['note'] = 'tooLong';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'leaveId' => $leaveId,
                'note' => $note === '' ? null : $note,
            ],
            'fields' => [],
        ];
    }

    /** Checkbox coercion (`true` / `'true'` → true, everything else → false). */
    private static function checkbox(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }

    private static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /** int coercion mirroring `z.coerce.number().int()`. */
    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /** UTC-midnight timestamp of a date key, or null when malformed. */
    private static function parseKey(string $key): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $key.' 00:00:00', new \DateTimeZone('UTC'));

        return $date === false ? null : $date->getTimestamp();
    }
}
