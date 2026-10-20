<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Messaging\Schedule;
use PHPUnit\Framework\TestCase;

/**
 * The automatic-message schedule math, ported from
 * src/modules/messaging/schedule.test.ts.
 */
class MessagingScheduleTest extends TestCase
{
    private const TZ = 'Africa/Addis_Ababa';

    /** @var array<string, mixed> */
    private const S = Schedule::DEFAULT_MESSAGING_SETTINGS;

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    private function iso(mixed $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.v\Z');
    }

    public function test_the_confirmation_is_due_now_and_expires_at_the_end_of_the_clinic_day(): void
    {
        $now = $this->at('2026-10-10T07:30:00.000Z');
        $schedule = Schedule::appointmentConfirmationSchedule($now, self::S);

        $this->assertSame($now->getTimestamp(), $schedule['dueAt']->getTimestamp());
        $this->assertNull($schedule['expiresAt']);
        // Next local midnight.
        $this->assertSame('2026-10-10T21:00:00.000Z', $this->iso($schedule['sendBy']));
    }

    public function test_the_reminder_is_null_once_the_appointment_started(): void
    {
        $appointment = [
            'id' => 'a',
            'startsAt' => $this->at('2026-10-13T09:00:00.000Z'),
            'createdAt' => $this->at('2026-10-01T00:00:00Z'),
        ];

        $this->assertNull(Schedule::appointmentReminderSchedule(
            $appointment,
            self::S,
            $this->at('2026-10-13T09:00:00.000Z'),
        ));
    }

    public function test_the_reminder_is_the_day_before_at_the_reminder_time(): void
    {
        $appointment = [
            'id' => 'a',
            'startsAt' => $this->at('2026-10-13T09:00:00.000Z'),
            'createdAt' => $this->at('2026-10-01T00:00:00Z'),
        ];

        $schedule = Schedule::appointmentReminderSchedule($appointment, self::S, $this->at('2026-10-09T00:00:00Z'));

        // 14:00 local on the previous day.
        $this->assertSame('2026-10-12T11:00:00.000Z', $this->iso($schedule['dueAt']));
        // startsAt − 60 min.
        $this->assertSame('2026-10-13T08:00:00.000Z', $this->iso($schedule['sendBy']));
        $this->assertSame($appointment['startsAt']->getTimestamp(), $schedule['expiresAt']->getTimestamp());
    }

    public function test_a_late_booking_falls_due_immediately(): void
    {
        $appointment = [
            'id' => 'a',
            'startsAt' => $this->at('2026-10-13T09:00:00.000Z'),
            'createdAt' => $this->at('2026-10-12T12:00:00.000Z'),
        ];

        $schedule = Schedule::appointmentReminderSchedule($appointment, self::S, $this->at('2026-10-12T15:00:00.000Z'));

        $this->assertSame('2026-10-12T15:00:00.000Z', $this->iso($schedule['dueAt']));
    }

    public function test_the_same_day_reminder_is_due_at_eight_local(): void
    {
        $appointment = [
            'id' => 'a',
            'startsAt' => $this->at('2026-10-13T09:00:00.000Z'),
            'createdAt' => $this->at('2026-10-01T00:00:00Z'),
        ];

        // Oct 13 00:00 local.
        $schedule = Schedule::appointmentSameDaySchedule($appointment, self::S, $this->at('2026-10-12T21:00:00.000Z'));

        $this->assertSame('2026-10-13T05:00:00.000Z', $this->iso($schedule['dueAt']));
        $this->assertSame($appointment['startsAt']->getTimestamp(), $schedule['sendBy']->getTimestamp());
        $this->assertSame($appointment['startsAt']->getTimestamp(), $schedule['expiresAt']->getTimestamp());
    }

    public function test_the_same_day_reminder_falls_due_now_when_the_window_passed(): void
    {
        $appointment = [
            'id' => 'a',
            'startsAt' => $this->at('2026-10-13T09:00:00.000Z'),
            'createdAt' => $this->at('2026-10-01T00:00:00Z'),
        ];

        $now = $this->at('2026-10-13T06:30:00.000Z');
        $schedule = Schedule::appointmentSameDaySchedule($appointment, self::S, $now);

        $this->assertSame('2026-10-13T06:30:00.000Z', $this->iso($schedule['dueAt']));
    }

    public function test_the_no_show_message_is_due_the_next_morning(): void
    {
        $schedule = Schedule::noShowSchedule($this->at('2026-10-10T15:00:00.000Z'), self::S);

        $this->assertSame('2026-10-11T06:00:00.000Z', $this->iso($schedule['dueAt']));
        $this->assertNull($schedule['sendBy']);
        $this->assertSame('2026-10-14T06:00:00.000Z', $this->iso($schedule['expiresAt']));
    }

    public function test_the_follow_up_leads_by_the_lead_days(): void
    {
        $schedule = Schedule::followUpSchedule(
            $this->at('2026-10-20T00:00:00.000Z'),
            self::S,
            $this->at('2026-10-01T00:00:00Z'),
        );

        $this->assertSame('2026-10-17T06:00:00.000Z', $this->iso($schedule['dueAt']));
        $this->assertNull($schedule['sendBy']);
        // +7 days at 23:59 local.
        $this->assertSame('2026-10-27T20:59:00.000Z', $this->iso($schedule['expiresAt']));
    }

    public function test_the_follow_up_falls_due_now_when_the_lead_window_passed(): void
    {
        $now = $this->at('2026-10-18T00:00:00.000Z');
        $schedule = Schedule::followUpSchedule($this->at('2026-10-20T00:00:00.000Z'), self::S, $now);

        $this->assertSame($now->getTimestamp(), $schedule['dueAt']->getTimestamp());
    }

    public function test_the_thank_you_is_the_morning_after(): void
    {
        $schedule = Schedule::thankYouSchedule([
            'id' => 'a',
            'startsAt' => $this->at('2026-10-10T09:00:00.000Z'),
            'createdAt' => $this->at('2026-10-01T00:00:00Z'),
        ], self::S);

        $this->assertSame('2026-10-11T06:00:00.000Z', $this->iso($schedule['dueAt']));
        $this->assertNull($schedule['sendBy']);
        $this->assertNull($schedule['expiresAt']);
    }

    public function test_the_birthday_message_expires_at_the_next_local_midnight(): void
    {
        $schedule = Schedule::birthdaySchedule($this->at('2026-10-10T20:00:00.000Z'), self::S);

        $this->assertSame('2026-10-10T05:00:00.000Z', $this->iso($schedule['dueAt']));
        $this->assertNull($schedule['sendBy']);
        $this->assertSame('2026-10-10T21:00:00.000Z', $this->iso($schedule['expiresAt']));
    }

    public function test_the_recall_is_valid_for_thirty_days(): void
    {
        $now = $this->at('2026-10-10T12:00:00.000Z');
        $schedule = Schedule::recallSchedule($now);

        $this->assertSame($now->getTimestamp(), $schedule['dueAt']->getTimestamp());
        $this->assertSame('2026-11-09T12:00:00.000Z', $this->iso($schedule['expiresAt']));
    }

    public function test_the_immediate_task_is_due_now_and_never_expires(): void
    {
        $now = $this->at('2026-10-10T12:00:00.000Z');
        $schedule = Schedule::immediateSchedule($now);

        $this->assertSame($now->getTimestamp(), $schedule['dueAt']->getTimestamp());
        $this->assertNull($schedule['sendBy']);
        $this->assertNull($schedule['expiresAt']);
    }

    public function test_the_recall_period_key_is_clinic_local(): void
    {
        $this->assertSame('2026-10', Schedule::recallPeriodKey($this->at('2026-10-10T22:00:00.000Z'), self::TZ));
        // 23:00 local on Oct 31.
        $this->assertSame('2026-10', Schedule::recallPeriodKey($this->at('2026-10-31T20:00:00.000Z'), self::TZ));
        // 00:00 local on Nov 1.
        $this->assertSame('2026-11', Schedule::recallPeriodKey($this->at('2026-10-31T21:00:00.000Z'), self::TZ));
    }

    public function test_the_later_of_picks_the_latest_instant(): void
    {
        $a = $this->at('2026-01-01T00:00:00Z');
        $b = $this->at('2026-06-01T00:00:00Z');

        $this->assertSame($b->getTimestamp(), Schedule::laterOf($a, $b)->getTimestamp());
        $this->assertSame($b->getTimestamp(), Schedule::laterOf($b, $a)->getTimestamp());
        $this->assertSame($a->getTimestamp(), Schedule::laterOf($a, $a)->getTimestamp());
    }
}
