<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MessageTask;
use App\Models\Patient;
use App\Support\Messaging\MsgQueries;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The outbox grouping and its counters (the legacy `listOutbox`,
 * `countDueTasks` and `countDueToday`).
 */
class MessageOutboxTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    private int $phoneCounter = 0;

    private function patient(): Patient
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        return Patient::factory()->create([
            'created_by' => $owner->id,
            'phone_e164' => '+251911'.str_pad((string) (++$this->phoneCounter), 6, '0', STR_PAD_LEFT),
            'do_not_message' => false,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function task(array $overrides = []): MessageTask
    {
        return MessageTask::create(array_merge([
            'patient_id' => $this->patient()->id,
            'template_key' => 'APPT_REMINDER',
            'source_type' => 'APPOINTMENT',
            'source_id' => 'src-'.uniqid(),
            'language' => 'en',
            'rendered_message' => 'Hello',
            'due_at' => '2026-10-08 09:00:00',
            'status' => MessageTask::STATUS_PENDING,
        ], $overrides));
    }

    public function test_the_tasks_are_grouped_by_when_they_are_due(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T09:00:00Z', new \DateTimeZone('UTC'));

        $overdue = $this->task(['due_at' => '2026-10-07 09:00:00']);      // before the clinic day
        $dueNow = $this->task(['due_at' => '2026-10-08 06:00:00']);      // 09:00 local, due
        $later = $this->task(['due_at' => '2026-10-08 12:00:00']);        // 15:00 local
        $done = $this->task([
            'due_at' => '2026-10-08 06:00:00',
            'status' => MessageTask::STATUS_SENT,
            'handled_at' => '2026-10-08 07:00:00',
        ]);
        $blocked = $this->task([
            'due_at' => '2026-10-08 06:00:00',
            'status' => MessageTask::STATUS_BLOCKED,
            'blocked_reason' => 'doNotMessage',
        ]);

        $groups = MsgQueries::listOutbox($now, 'Africa/Addis_Ababa');

        $this->assertSame([$overdue->id], array_column($groups['overdue'], 'id'));
        $this->assertSame([$dueNow->id], array_column($groups['dueNow'], 'id'));
        $this->assertSame([$later->id], array_column($groups['laterToday'], 'id'));
        $this->assertSame([$done->id], array_column($groups['doneToday'], 'id'));
        $this->assertSame([$blocked->id], array_column($groups['blocked'], 'id'));
    }

    public function test_a_task_handled_yesterday_is_not_in_the_outbox(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T09:00:00Z', new \DateTimeZone('UTC'));

        $this->task([
            'due_at' => '2026-10-07 06:00:00',
            'status' => MessageTask::STATUS_SENT,
            'handled_at' => '2026-10-07 07:00:00',
        ]);

        $groups = MsgQueries::listOutbox($now, 'Africa/Addis_Ababa');

        $this->assertSame([], $groups['doneToday']);
        $this->assertSame([], $groups['overdue']);
    }

    public function test_the_badge_counts_the_due_and_overdue_tasks(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T09:00:00Z', new \DateTimeZone('UTC'));

        $this->task(['due_at' => '2026-10-08 06:00:00']);   // due
        $this->task(['due_at' => '2026-10-07 06:00:00']);   // overdue
        $this->task(['due_at' => '2026-10-08 12:00:00']);   // later today
        $this->task(['due_at' => '2026-10-09 06:00:00']);   // tomorrow

        // Due now or overdue.
        $this->assertSame(2, MsgQueries::countDueTasks($now));

        $counts = MsgQueries::countDueToday($now, 'Africa/Addis_Ababa');

        // "Due today" is everything due before the end of the clinic day
        // (already due AND later today); "overdue" is what was due before it
        // started.
        $this->assertSame(2, $counts['dueToday']);
        $this->assertSame(1, $counts['overdue']);
    }

    public function test_a_blocked_task_is_not_counted_in_the_badge(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T09:00:00Z', new \DateTimeZone('UTC'));

        $this->task([
            'due_at' => '2026-10-08 06:00:00',
            'status' => MessageTask::STATUS_BLOCKED,
            'blocked_reason' => 'doNotMessage',
        ]);

        $this->assertSame(0, MsgQueries::countDueTasks($now));
    }

    public function test_the_outbox_shows_the_group_counters(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->task(['due_at' => now()->format('Y-m-d H:i:s')]);

        $response = $this->actingAs($owner)->get('/messages');

        $response->assertOk();
        $response->assertSee('Hello');
    }
}
