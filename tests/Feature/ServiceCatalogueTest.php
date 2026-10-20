<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Service;
use App\Models\ServicePriceHistory;
use App\Support\Money;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The service catalogue: create/edit, the price history, the active flag and
 * the image upload. OWNER only (enforced by the route gate).
 */
class ServiceCatalogueTest extends TestCase
{
    use CreatesSales;
    use CreatesUsers;
    use RefreshDatabase;

    /** @return array<string, string> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nameEn' => 'Eye Consultation',
            'nameAm' => '',
            'category' => 'consultation',
            'currentPrice' => '300.00',
            'isPublic' => 'true',
            'publicDescriptionEn' => '',
            'publicDescriptionAm' => '',
            'receiptLabelEn' => '',
            'receiptLabelAm' => '',
            'displayOrder' => '0',
            'imageUrl' => '',
        ], $overrides);
    }

    public function test_the_owner_creates_a_service(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)
            ->post('/dashboard/services', $this->validPayload())
            ->assertRedirect('/dashboard/services');

        $service = Service::query()->firstOrFail();

        $this->assertSame('Eye Consultation', $service->name);
        $this->assertSame('Eye Consultation', $service->name_en);
        $this->assertNull($service->name_am);
        $this->assertSame('consultation', $service->category);
        $this->assertSame('300.00', Money::toDecimalString($service->current_price));
        $this->assertTrue($service->active);
        $this->assertTrue($service->is_public);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Service',
            'entity_id' => $service->id,
            'action' => 'SERVICE_CREATED',
        ]);
    }

    public function test_the_english_name_is_required(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)
            ->post('/dashboard/services', $this->validPayload(['nameEn' => '   ']))
            ->assertSessionHasErrors('nameEn');

        $this->assertSame(0, Service::query()->count());
    }

    public function test_the_category_must_be_a_fixed_key(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)
            ->post('/dashboard/services', $this->validPayload(['category' => 'bogus']))
            ->assertSessionHasErrors('category');
    }

    public function test_the_price_must_match_the_numeric_shape(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        foreach (['abc', '-1', '1.234', '10000000000.00'] as $bad) {
            $this->actingAs($owner->fresh())
                ->post('/dashboard/services', $this->validPayload(['currentPrice' => $bad]))
                ->assertSessionHasErrors('currentPrice');
        }
    }

    public function test_the_name_is_capped_at_120_characters(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)
            ->post('/dashboard/services', $this->validPayload(['nameEn' => str_repeat('a', 121)]))
            ->assertSessionHasErrors('nameEn');
    }

    public function test_unchecked_is_public_stores_false(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $payload = $this->validPayload();
        unset($payload['isPublic']);

        $this->actingAs($owner)->post('/dashboard/services', $payload)->assertRedirect();

        $this->assertFalse(Service::query()->firstOrFail()->is_public);
    }

    public function test_the_owner_updates_a_service(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['name' => 'Old Name', 'name_en' => 'Old Name', 'current_price' => '100.00']);

        $this->actingAs($owner)
            ->post("/dashboard/services/{$service->id}", $this->validPayload([
                'nameEn' => 'New Name',
                'currentPrice' => '250.00',
            ]))
            ->assertRedirect('/dashboard/services');

        $service->refresh();

        $this->assertSame('New Name', $service->name);
        $this->assertSame('New Name', $service->name_en);
        $this->assertSame('250.00', Money::toDecimalString($service->current_price));

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Service',
            'entity_id' => $service->id,
            'action' => 'SERVICE_UPDATED',
        ]);
    }

    public function test_a_price_change_is_recorded_in_the_history(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '100.00']);

        $this->actingAs($owner)
            ->post("/dashboard/services/{$service->id}", $this->validPayload(['currentPrice' => '250.00']))
            ->assertRedirect();

        $history = ServicePriceHistory::query()->firstOrFail();

        $this->assertSame($service->id, $history->service_id);
        $this->assertSame('100.00', Money::toDecimalString($history->old_price));
        $this->assertSame('250.00', Money::toDecimalString($history->new_price));
        $this->assertSame($owner->id, $history->changed_by);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Service',
            'entity_id' => $service->id,
            'action' => 'SERVICE_PRICE_CHANGED',
        ]);
    }

    public function test_no_price_change_writes_no_history_row(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '100.00']);

        $this->actingAs($owner)
            ->post("/dashboard/services/{$service->id}", $this->validPayload(['currentPrice' => '100.00']))
            ->assertRedirect();

        $this->assertSame(0, ServicePriceHistory::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'SERVICE_PRICE_CHANGED']);
    }

    public function test_the_status_toggle_writes_an_audit_row(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $this->actingAs($owner)
            ->post("/dashboard/services/{$service->id}/status", ['active' => 'false'])
            ->assertRedirect();

        $this->assertFalse($service->fresh()->active);

        $audit = AuditLog::query()->where('action', 'SERVICE_STATUS_CHANGED')->firstOrFail();
        $this->assertTrue($audit->old_json['active']);
        $this->assertFalse($audit->new_json['active']);
    }

    public function test_an_unchanged_status_writes_nothing(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $this->actingAs($owner)
            ->post("/dashboard/services/{$service->id}/status", ['active' => 'true'])
            ->assertRedirect();

        $this->assertSame(0, AuditLog::query()->where('action', 'SERVICE_STATUS_CHANGED')->count());
    }

    public function test_an_inactive_service_disappears_from_the_new_sale_picker(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $active = $this->createService(['name' => 'Active Service', 'name_en' => 'Active Service']);
        $inactive = $this->createService(['name' => 'Inactive Service', 'name_en' => 'Inactive Service', 'active' => false]);

        $response = $this->actingAs($owner)->get('/sales/new');

        $response->assertOk();
        $response->assertSee('Active Service');
        $response->assertDontSee('Inactive Service');
    }

    public function test_the_service_list_can_be_filtered(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $this->createService(['name' => 'Glasses Fitting', 'name_en' => 'Glasses Fitting', 'category' => 'glasses']);
        $this->createService(['name' => 'Retina Exam', 'name_en' => 'Retina Exam', 'category' => 'diagnostics']);

        $response = $this->actingAs($owner->fresh())->get('/dashboard/services?category=glasses');

        $response->assertOk();
        $response->assertSee('Glasses Fitting');
        $response->assertDontSee('Retina Exam');
    }

    public function test_the_service_list_can_be_searched_by_name(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $this->createService(['name' => 'Glasses Fitting', 'name_en' => 'Glasses Fitting']);
        $this->createService(['name' => 'Retina Exam', 'name_en' => 'Retina Exam']);

        $response = $this->actingAs($owner->fresh())->get('/dashboard/services?q=Glasses');

        $response->assertOk();
        $response->assertSee('Glasses Fitting');
        $response->assertDontSee('Retina Exam');
    }

    public function test_an_image_upload_is_stored_and_linked(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        // A tiny but valid PNG (magic bytes are what the MIME sniffer reads).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->actingAs($owner)
            ->post('/dashboard/services', $this->validPayload([
                'image' => UploadedFile::fake()->createWithContent('service.png', $png),
            ]))
            ->assertRedirect('/dashboard/services');

        $service = Service::query()->firstOrFail();

        $this->assertNotNull($service->image_url);
        $this->assertStringStartsWith('/uploads/service-', $service->image_url);
        $this->assertStringEndsWith('.png', $service->image_url);
    }

    public function test_an_image_with_a_wrong_type_is_rejected(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)
            ->post('/dashboard/services', $this->validPayload([
                'image' => UploadedFile::fake()->createWithContent('service.gif', 'GIF89a-not-really'),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, Service::query()->count());
    }

    public function test_an_image_over_one_mebibyte_is_rejected(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->actingAs($owner)
            ->post('/dashboard/services', $this->validPayload([
                'image' => UploadedFile::fake()->createWithContent('service.png', $png.str_repeat('0', 1024 * 1024)),
            ]))
            ->assertSessionHasErrors('image');
    }

    public function test_a_service_is_never_hard_deleted(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService();

        $this->post("/dashboard/services/{$service->id}/delete")->assertNotFound();
        $this->assertSame(1, Service::query()->count());
    }

    public function test_the_price_history_is_visible_on_the_edit_screen(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = $this->createService(['current_price' => '100.00']);

        $this->actingAs($owner)
            ->post("/dashboard/services/{$service->id}", $this->validPayload(['currentPrice' => '250.00']))
            ->assertRedirect();

        $response = $this->actingAs($owner->fresh())->get("/dashboard/services/{$service->id}/edit");

        $response->assertOk();
        $response->assertSee('ETB 100.00');
        $response->assertSee('ETB 250.00');
    }
}
