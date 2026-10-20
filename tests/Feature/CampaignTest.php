<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignBatch;
use App\Models\MessageTask;
use App\Models\Patient;
use App\Models\Setting;
use App\Support\Messaging\RecipientFilter;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Campaigns: the audience preview, creation (INDIVIDUAL and GROUP), the
 * recipient cap and the batch handling.
 */
class CampaignTest extends TestCase
{
    use CreatesPatients;
    use CreatesUsers;
    use RefreshDatabase;

    public function test_reception_cannot_create_a_campaign_by_default(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($reception)->get('/messages/campaigns/new')->assertForbidden();
        $this->actingAs($reception->fresh())->post('/messages/campaigns', [])->assertForbidden();
    }

    public function test_reception_can_create_a_campaign_when_the_setting_is_on(): void
    {
        Setting::create(['key' => 'reception_can_send_campaigns', 'value' => true]);
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $this->actingAs($reception)->get('/messages/campaigns/new')->assertOk();
    }

    public function test_the_owner_creates_an_individual_campaign(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient();

        $filter = RecipientFilter::empty();
        $filter['handPickedIds'] = [$patient->id];

        $this->actingAs($owner)->post('/messages/campaigns', [
            'title' => 'Clinic announcement',
            'body' => 'Dear {name}, we are open on Sunday.',
            'languageMode' => 'PATIENT',
            'deliveryMode' => 'INDIVIDUAL',
            'filter' => json_encode($filter),
        ])->assertRedirect();

        $campaign = Campaign::query()->firstOrFail();

        $this->assertSame('Clinic announcement', (string) $campaign->title);
        $this->assertSame(Campaign::STATUS_ACTIVE, (string) $campaign->status);
        $this->assertSame('INDIVIDUAL', (string) $campaign->delivery_mode);

        $task = MessageTask::query()->firstOrFail();

        $this->assertSame($patient->id, $task->patient_id);
        $this->assertSame('CAMPAIGN', (string) $task->source_type);
        // The body is rendered per recipient: {name} becomes the patient name.
        $this->assertStringContainsString($patient->full_name, (string) $task->rendered_message);
        $this->assertStringNotContainsString('{name}', (string) $task->rendered_message);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Campaign',
            'entity_id' => $campaign->id,
            'action' => 'CAMPAIGN_CREATED',
        ]);
    }

    public function test_an_empty_filter_matches_nobody(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $this->createPatient();

        $this->actingAs($owner)->post('/messages/campaigns', [
            'title' => 'Nobody',
            'body' => 'Hello {name}',
            'languageMode' => 'PATIENT',
            'deliveryMode' => 'INDIVIDUAL',
            'filter' => json_encode(RecipientFilter::empty()),
        ])->assertRedirect();

        $this->assertSame(0, MessageTask::query()->count());
    }

    public function test_the_message_body_must_be_valid(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/messages/campaigns', [
            'title' => 'Bad',
            'body' => 'Hello {nope}',
            'languageMode' => 'PATIENT',
            'deliveryMode' => 'INDIVIDUAL',
            'filter' => json_encode(RecipientFilter::empty()),
        ])->assertSessionHasErrors('body');
    }

    public function test_the_title_is_required(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/messages/campaigns', [
            'title' => '',
            'body' => 'Hello {name}',
            'languageMode' => 'PATIENT',
            'deliveryMode' => 'INDIVIDUAL',
        ])->assertSessionHasErrors('title');
    }

    public function test_a_group_campaign_splits_the_recipients_into_batches(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $patients = [];
        for ($i = 0; $i < 7; $i++) {
            $patients[] = $this->createPatient()->id;
        }

        $filter = RecipientFilter::empty();
        $filter['handPickedIds'] = $patients;

        $this->actingAs($owner)->post('/messages/campaigns', [
            'title' => 'Group message',
            'body' => 'Hello {name}',
            'languageMode' => 'PATIENT',
            'deliveryMode' => 'GROUP',
            'groupBatchSize' => 3,
            'acknowledgedGroupWarning' => 'true',
            'filter' => json_encode($filter),
        ])->assertRedirect();

        $campaign = Campaign::query()->firstOrFail();

        $this->assertSame('GROUP', (string) $campaign->delivery_mode);
        $this->assertSame(3, CampaignBatch::query()->where('campaign_id', $campaign->id)->count());
        $this->assertSame([3, 3, 1], CampaignBatch::query()
            ->where('campaign_id', $campaign->id)
            ->orderBy('batch_number')
            ->pluck('size')
            ->map(fn ($size): int => (int) $size)
            ->all());
        $this->assertSame(7, MessageTask::query()->count());
    }

    public function test_a_group_campaign_is_blocked_for_a_filtered_audience(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient();

        $filter = RecipientFilter::empty();
        $filter['handPickedIds'] = [$patient->id];
        $filter['tags'] = ['glasses'];

        $this->actingAs($owner)->post('/messages/campaigns', [
            'title' => 'Group message',
            'body' => 'Hello {name}',
            'languageMode' => 'PATIENT',
            'deliveryMode' => 'GROUP',
            'acknowledgedGroupWarning' => 'true',
            'filter' => json_encode($filter),
        ])->assertSessionHas('actionError', 'groupBlocked');

        $this->assertSame(0, Campaign::query()->count());
    }

    public function test_the_owner_can_mark_a_batch_as_sent(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient();

        $filter = RecipientFilter::empty();
        $filter['handPickedIds'] = [$patient->id];

        $this->actingAs($owner)->post('/messages/campaigns', [
            'title' => 'Group message',
            'body' => 'Hello {name}',
            'languageMode' => 'PATIENT',
            'deliveryMode' => 'GROUP',
            'groupBatchSize' => 15,
            'acknowledgedGroupWarning' => 'true',
            'filter' => json_encode($filter),
        ])->assertRedirect();

        $campaign = Campaign::query()->firstOrFail();
        $batch = CampaignBatch::query()->where('campaign_id', $campaign->id)->firstOrFail();

        $this->actingAs($owner->fresh())
            ->post("/messages/campaigns/{$campaign->id}/batches/{$batch->id}", ['action' => 'sent'])
            ->assertRedirect();

        $this->assertSame(CampaignBatch::STATUS_SENT, (string) $batch->fresh()->status);
        $this->assertSame(MessageTask::STATUS_SENT, (string) MessageTask::query()->firstOrFail()->status);
    }

    public function test_a_campaign_detail_page_shows_the_queue(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $patient = $this->createPatient(['full_name' => 'Campaign Patient']);

        $filter = RecipientFilter::empty();
        $filter['handPickedIds'] = [$patient->id];

        $this->actingAs($owner)->post('/messages/campaigns', [
            'title' => 'Detail campaign',
            'body' => 'Hello {name}',
            'languageMode' => 'PATIENT',
            'deliveryMode' => 'INDIVIDUAL',
            'filter' => json_encode($filter),
        ])->assertRedirect();

        $campaign = Campaign::query()->firstOrFail();

        $response = $this->actingAs($owner->fresh())->get("/messages/campaigns/{$campaign->id}");

        $response->assertOk();
        $response->assertSee('Detail campaign');
        $response->assertSee('Campaign Patient');
    }

    public function test_a_missing_campaign_is_a_404(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->get('/messages/campaigns/01J0000000000000000000000')->assertNotFound();
    }
}
