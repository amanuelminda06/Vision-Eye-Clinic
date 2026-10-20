<?php

declare(strict_types=1);

namespace App\Support\Appointments;

/**
 * Appointment + follow-up form validation, ported from
 * src/modules/appointments/schemas.ts.
 *
 * Field errors are `['field' => '<i18n key>']`; the view renders
 * `validation.<key>`. The FIRST issue per field wins.
 */
final class ApptValidation
{
    /**
     * Validate the appointment create/edit form.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function appointmentForm(array $values): array
    {
        $fields = [];

        $id = self::str($values['id'] ?? '');
        if ($id !== '' && mb_strlen($id) > 64) {
            $fields['id'] = 'tooLong';
        }

        $patientId = self::str($values['patientId'] ?? '');
        if (mb_strlen($patientId) > 64) {
            $fields['patientId'] = 'tooLong';
        }

        $quickName = trim(self::str($values['quickName'] ?? ''));
        if (mb_strlen($quickName) > 100) {
            $fields['quickName'] = 'tooLong';
        }

        $quickPhone = trim(self::str($values['quickPhone'] ?? ''));
        if (mb_strlen($quickPhone) > 32) {
            $fields['quickPhone'] = 'tooLong';
        }

        $doctorId = self::str($values['doctorId'] ?? '');
        if ($doctorId === '') {
            $fields['doctorId'] = 'doctorRequired';
        } elseif (mb_strlen($doctorId) > 64) {
            $fields['doctorId'] = 'tooLong';
        }

        $dateKey = self::str($values['dateKey'] ?? '');
        if (preg_match(Constants::DATE_RE, $dateKey) !== 1) {
            $fields['dateKey'] = 'invalidDate';
        }

        $startTime = self::str($values['startTime'] ?? '');
        if (preg_match(Constants::APPT_TIME_RE, $startTime) !== 1) {
            $fields['startTime'] = 'invalidTime';
        }

        $durationMin = self::toInt($values['durationMin'] ?? Constants::DEFAULT_DURATION);
        if ($durationMin === null || $durationMin < Constants::DURATIONS[0] || $durationMin > 180) {
            $fields['durationMin'] = 'invalidDuration';
        }

        $serviceId = self::str($values['serviceId'] ?? '');
        if (mb_strlen($serviceId) > 64) {
            $fields['serviceId'] = 'tooLong';
        }

        $notes = trim(self::str($values['notes'] ?? ''));
        if (mb_strlen($notes) > Constants::MAX_APPT_NOTES) {
            $fields['notes'] = 'tooLong';
        }

        // Refine: a patient is required — either an existing one or a
        // quick-create with both a name and a phone.
        if (! isset($fields['patientId']) && $patientId === '' && ! ($quickName !== '' && $quickPhone !== '')) {
            $fields['patientId'] = 'patientRequired';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'id' => $id === '' ? null : $id,
                'patientId' => $patientId === '' ? null : $patientId,
                'quickName' => $quickName === '' ? null : $quickName,
                'quickPhone' => $quickPhone === '' ? null : $quickPhone,
                'doctorId' => $doctorId,
                'dateKey' => $dateKey,
                'startTime' => $startTime,
                'durationMin' => $durationMin,
                'serviceId' => $serviceId === '' ? null : $serviceId,
                'notes' => $notes === '' ? null : $notes,
                'overrideDoubleBooking' => self::checkbox($values['overrideDoubleBooking'] ?? false),
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the appointment status transition form.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function appointmentStatus(array $values): array
    {
        $fields = [];

        $id = self::str($values['id'] ?? '');
        if ($id === '') {
            $fields['id'] = 'required';
        } elseif (mb_strlen($id) > 64) {
            $fields['id'] = 'tooLong';
        }

        $status = self::str($values['status'] ?? '');
        if (! Constants::isAppointmentStatus($status)) {
            $fields['status'] = 'invalidStatus';
        }

        $cancelReason = trim(self::str($values['cancelReason'] ?? ''));
        if (mb_strlen($cancelReason) > Constants::MAX_CANCEL_REASON) {
            $fields['cancelReason'] = 'tooLong';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'id' => $id,
                'status' => $status,
                'cancelReason' => $cancelReason === '' ? null : $cancelReason,
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the follow-up form.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function followUpForm(array $values): array
    {
        $fields = [];

        $patientId = self::str($values['patientId'] ?? '');
        if ($patientId === '') {
            $fields['patientId'] = 'required';
        } elseif (mb_strlen($patientId) > 64) {
            $fields['patientId'] = 'tooLong';
        }

        $appointmentId = self::str($values['appointmentId'] ?? '');
        if (mb_strlen($appointmentId) > 64) {
            $fields['appointmentId'] = 'tooLong';
        }

        $preset = self::str($values['preset'] ?? '');
        if (! Constants::isFollowUpPreset($preset)) {
            $fields['preset'] = 'invalidPreset';
        }

        $dueDate = self::str($values['dueDate'] ?? '');
        if ($dueDate !== '' && preg_match(Constants::DATE_RE, $dueDate) !== 1) {
            $fields['dueDate'] = 'invalidDate';
        }

        $reason = trim(self::str($values['reason'] ?? ''));
        if (mb_strlen($reason) > Constants::MAX_FOLLOW_UP_REASON) {
            $fields['reason'] = 'tooLong';
        }

        // Refine: the custom preset needs an explicit due date.
        if (! isset($fields['preset']) && $preset === 'custom' && $dueDate === '') {
            $fields['dueDate'] = 'invalidDate';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => [
                'patientId' => $patientId,
                'appointmentId' => $appointmentId === '' ? null : $appointmentId,
                'preset' => $preset,
                'dueDate' => $dueDate === '' ? null : $dueDate,
                'reason' => $reason === '' ? null : $reason,
            ],
            'fields' => [],
        ];
    }

    /**
     * Validate the follow-up status form.
     *
     * @param  array<string, mixed>  $values
     * @return array{values: array<string, mixed>|null, fields: array<string, string>}
     */
    public static function followUpStatus(array $values): array
    {
        $fields = [];

        $id = self::str($values['id'] ?? '');
        if ($id === '') {
            $fields['id'] = 'required';
        } elseif (mb_strlen($id) > 64) {
            $fields['id'] = 'tooLong';
        }

        $status = self::str($values['status'] ?? '');
        if ($status !== 'DONE' && $status !== 'CANCELLED') {
            $fields['status'] = 'invalidStatus';
        }

        if ($fields !== []) {
            return ['values' => null, 'fields' => $fields];
        }

        return [
            'values' => ['id' => $id, 'status' => $status],
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
}
