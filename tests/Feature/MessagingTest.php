<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MessageDraft;
use App\Models\MessageLibraryItem;
use App\Models\MessageTask;
use App\Models\MessageTemplate;
use App\Models\Patient;
use App\Support\Messaging\MessageHash;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The messaging surfaces: the outbox and its task actions, the template
 * editor, the library, the drafts and the role gates.
 *
 * MANUAL SEND ONLY — the app never transmits anything.
 */
class MessagingTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    protected function createTask(array $overrides = [], ?Patient $patient = null): MessageTask
    {
        $patient ??= $this->createPatient();

        return MessageTask::create(array_merge([
            'patient_id' => $patient->id,
            'template_key' => 'APPT_REMINDER',
            'source_type' => 'APPOINTMENT',
            'source_id' => 'src-1',
            'language' => 'en',
            'rendered_message' => 'Dear {name}, see you on {date}.',
            'due_at' => now(),
            'status' => MessageTask::STATUS_PENDING,
        ], $overrides));
    }

    public function test_anonymous_requests_redirect_to_the_secret_login(): void
    {
        $this->get('/messages')->assertRedirect(login_path());
        $this->get('/messages/library')->assertRedirect(login_path());
        $this->get('/messages/drafts')->assertRedirect(login_path());
        $this->get('/messages/templates')->assertRedirect(login_path());
        $this->get('/messages/campaigns')->assertRedirect(login_path());
        $this->get('/messages/reports')->assertRedirect(login_path());
        $this->post('/messages/abc/sent')->assertRedirect(login_path());
    }

    public function test_owner_and_reception_can_open_the_outbox(): void
    {
        foreach ([Roles::OWNER, Roles::RECEPTION] as $role) {
            $user = $this->createUser(['role' => $role]);

            $this->actingAs($user)->get('/messages')->assertOk();
            $this->actingAs($user->fresh())->get('/messages/library')->assertOk();
            $this->actingAs($user->fresh())->get('/messages/drafts')->assertOk();
            $this->actingAs($user->fresh())->get('/messages/campaigns')->assertOk();
        }
    }

    public function test_staff_is_blocked_from_every_message_surface(): void
    {
        $staff = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($staff)->get('/messages')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/messages/library')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/messages/drafts')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/messages/templates')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/messages/campaigns')->assertRedirect('/403');
        $this->actingAs($staff->fresh())->get('/messages/reports')->assertRedirect('/403');
    }

    public function test_reception_cannot_edit_the_templates_or_read_the_reports(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($reception)->get('/messages/templates')->assertRedirect('/403');
        $this->actingAs($reception->fresh())->get('/messages/reports')->assertRedirect('/403');
        $this->actingAs($reception->fresh())->post('/messages/templates', [])->assertForbidden();
    }

    public function test_the_outbox_lists_the_prepared_messages(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient(['full_name' => 'Queue Patient']);
        $this->createTask([], $patient);

        $response = $this->actingAs($owner)->get('/messages');

        $response->assertOk();
        $response->assertSee('Queue Patient');
    }

    public function test_opening_a_task_rechecks_the_eligibility(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient(['do_not_message' => true]);
        $task = $this->createTask([], $patient);

        $this->actingAs($owner)->post("/messages/{$task->id}/open")->assertRedirect();

        $task->refresh();

        $this->assertSame(MessageTask::STATUS_BLOCKED, (string) $task->status);
        $this->assertSame('doNotMessage', $task->blocked_reason);
    }

    public function test_opening_an_eligible_task_marks_it_opened(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $task = $this->createTask();

        $this->actingAs($owner)->post("/messages/{$task->id}/open")->assertRedirect();

        $task->refresh();

        $this->assertSame(MessageTask::STATUS_OPENED, (string) $task->status);
        $this->assertNull($task->blocked_reason);
    }

    public function test_a_blocked_task_is_never_deleted(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient(['active' => false]);
        $task = $this->createTask([], $patient);

        $this->actingAs($owner)->post("/messages/{$task->id}/open")->assertRedirect();

        $this->assertSame(1, MessageTask::query()->count());
    }

    public function test_confirming_a_send_marks_the_task_sent_and_audits_it(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $task = $this->createTask();

        $this->actingAs($owner)->post("/messages/{$task->id}/sent")->assertRedirect();

        $task->refresh();

        $this->assertSame(MessageTask::STATUS_SENT, (string) $task->status);
        $this->assertSame($owner->id, $task->handled_by);
        $this->assertNotNull($task->handled_at);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'MessageTask',
            'entity_id' => $task->id,
            'action' => 'MESSAGE_SENT',
        ]);
    }

    public function test_confirming_a_send_twice_audits_only_once(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $task = $this->createTask();

        $this->actingAs($owner)->post("/messages/{$task->id}/sent")->assertRedirect();
        $this->actingAs($owner->fresh())->post("/messages/{$task->id}/sent")->assertRedirect();

        $this->assertSame(1, AuditLog::query()->where('action', 'MESSAGE_SENT')->count());
    }

    public function test_postponing_keeps_the_task_pending(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $task = $this->createTask(['status' => MessageTask::STATUS_OPENED]);

        $this->actingAs($owner)->post("/messages/{$task->id}/postpone")->assertRedirect();

        $this->assertSame(MessageTask::STATUS_PENDING, (string) $task->fresh()->status);
    }

    public function test_skipping_needs_a_reason(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $task = $this->createTask();

        $this->actingAs($owner)->post("/messages/{$task->id}/skip", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($owner->fresh())->post("/messages/{$task->id}/skip", ['reason' => 'Not reachable'])
            ->assertRedirect();

        $task->refresh();

        $this->assertSame(MessageTask::STATUS_SKIPPED, (string) $task->status);
        $this->assertSame('Not reachable', $task->skip_reason);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'MessageTask',
            'entity_id' => $task->id,
            'action' => 'MESSAGE_SKIPPED',
        ]);
    }

    public function test_editing_the_body_is_audited(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $task = $this->createTask(['rendered_message' => 'Original body']);

        $this->actingAs($owner)->post("/messages/{$task->id}/edit", ['message' => 'Edited body'])
            ->assertRedirect();

        $task->refresh();

        $this->assertSame('Edited body', $task->rendered_message);
        $this->assertNotNull($task->edited_at);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'MessageTask',
            'entity_id' => $task->id,
            'action' => 'MESSAGE_EDITED',
        ]);
    }

    public function test_a_sent_task_cannot_be_edited(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $task = $this->createTask(['status' => MessageTask::STATUS_SENT]);

        $this->actingAs($owner)->post("/messages/{$task->id}/edit", ['message' => 'Edited body'])
            ->assertSessionHas('actionError', 'taskAlreadyHandled');
    }

    public function test_the_owner_saves_a_template(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/messages/templates', [
            'key' => 'APPT_REMINDER',
            'language' => 'en',
            'body' => 'Dear {name}, see you on {date} at {time}.',
            'active' => 'true',
        ])->assertRedirect();

        $template = MessageTemplate::query()->firstOrFail();

        $this->assertSame('APPT_REMINDER', (string) $template->key);
        $this->assertSame('en', (string) $template->language);
        $this->assertTrue((bool) $template->active);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'MessageTemplate',
            'entity_id' => $template->id,
            'action' => 'MESSAGE_TEMPLATE_SAVED',
        ]);
    }

    public function test_a_template_with_an_unknown_variable_is_refused(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/messages/templates', [
            'key' => 'APPT_REMINDER',
            'language' => 'en',
            'body' => 'Dear {name}, see {doctor_name}.',
        ])->assertSessionHasErrors('body');

        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_a_template_needs_a_known_key_and_language(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/messages/templates', [
            'key' => 'BOGUS', 'language' => 'en', 'body' => 'Hi',
        ])->assertSessionHasErrors('key');

        $this->actingAs($owner->fresh())->post('/messages/templates', [
            'key' => 'CUSTOM', 'language' => 'fr', 'body' => 'Hi',
        ])->assertSessionHasErrors('language');
    }

    public function test_a_library_item_is_saved_and_duplicates_are_refused(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/messages/library', [
            'body' => 'Dear {name}, see you on {date}.',
            'language' => 'en',
            'category' => 'REMINDER',
        ])->assertRedirect();

        $item = MessageLibraryItem::query()->firstOrFail();
        $this->assertSame('Dear {name}, see you on {date}.', $item->body);

        // The same wording in the same language is a duplicate.
        $this->actingAs($owner->fresh())->post('/messages/library', [
            'body' => '  Dear {name},   see you on {date}.  ',
            'language' => 'en',
            'category' => 'REMINDER',
        ])->assertSessionHas('actionError', 'libraryDuplicate');

        // The same wording in another language is a separate item.
        $this->actingAs($owner->fresh())->post('/messages/library', [
            'body' => 'Dear {name}, see you on {date}.',
            'language' => 'am',
            'category' => 'REMINDER',
        ])->assertRedirect();

        $this->assertSame(2, MessageLibraryItem::query()->count());
    }

    public function test_a_library_item_is_archived_not_deleted(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $item = MessageLibraryItem::create([
            'title' => 'Archived',
            'body' => 'Hello there',
            'language' => 'en',
            'category' => 'OTHER',
            'normalized_hash' => MessageHash::messageBodyHash('Hello there'),
            'created_by' => $owner->id,
            'active' => true,
        ]);

        $this->actingAs($owner->fresh())->post("/messages/library/{$item->id}/toggle", [
            'field' => 'active',
            'value' => 'false',
        ])->assertRedirect();

        $this->assertSame(1, MessageLibraryItem::query()->count());
        $this->assertFalse((bool) $item->fresh()->active);
    }

    public function test_drafts_are_private_to_their_author(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $other = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($owner)->post('/messages/drafts', [
            'body' => 'My private draft',
            'language' => 'en',
            'title' => 'Draft one',
        ])->assertRedirect();

        $this->assertSame(1, MessageDraft::query()->count());

        $response = $this->actingAs($other->fresh())->get('/messages/drafts');
        $response->assertOk();
        $response->assertDontSee('My private draft');
    }

    public function test_a_draft_can_be_deleted_by_its_author(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $other = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($owner)->post('/messages/drafts', [
            'body' => 'My private draft',
            'language' => 'en',
        ])->assertRedirect();

        $draft = MessageDraft::query()->firstOrFail();

        $this->actingAs($other->fresh())->post("/messages/drafts/{$draft->id}/delete")->assertRedirect();
        $this->assertSame(1, MessageDraft::query()->count());

        $this->actingAs($owner->fresh())->post("/messages/drafts/{$draft->id}/delete")->assertRedirect();
        $this->assertSame(0, MessageDraft::query()->count());
    }

    public function test_the_badge_count_endpoint_returns_the_queue_size(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $this->createTask();

        $response = $this->actingAs($owner)->getJson('/api/messages/count');

        $response->assertOk();
        $response->assertJson(['ok' => true, 'count' => 1]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_badge_count_needs_a_session(): void
    {
        $this->getJson('/api/messages/count')->assertUnauthorized();
    }

    public function test_the_outbox_export_streams_a_csv(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient(['full_name' => 'Exported Patient']);
        $this->createTask([], $patient);

        $response = $this->actingAs($owner)->get('/messages/export?format=csv')->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('Exported Patient', $response->getContent());
    }

    public function test_the_cron_endpoint_expires_the_overdue_tasks(): void
    {
        config(['services.cron_secret' => 'cron-secret']);

        $owner = $this->createUser(['role' => Roles::OWNER]);
        $this->createTask([
            'status' => MessageTask::STATUS_PENDING,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer cron-secret')
            ->postJson('/api/jobs/messages');

        $response->assertOk();
        $response->assertJson(['ok' => true, 'expired' => 1]);

        $this->assertSame(1, MessageTask::query()->where('status', MessageTask::STATUS_EXPIRED)->count());
    }

    public function test_the_cron_endpoint_needs_the_secret(): void
    {
        config(['services.cron_secret' => 'cron-secret']);

        $this->postJson('/api/jobs/messages')->assertForbidden();
        $this->withHeader('Authorization', 'Bearer wrong')->postJson('/api/jobs/messages')->assertForbidden();
    }
}
