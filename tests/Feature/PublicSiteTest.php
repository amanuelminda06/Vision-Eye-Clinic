<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Feedback;
use App\Models\SiteContent;
use App\Models\Staff;
use App\Support\PublicSite\Content;
use App\Support\PublicSite\Projection;
use App\Support\PublicSite\Seo;
use App\Support\RateLimit;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPatients;
use Tests\Concerns\CreatesSales;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The public website: the pages, the CMS, the feedback intake and the leak
 * guarantees (no prices, no patient data).
 */
class PublicSiteTest extends TestCase
{
    use CreatesPatients;
    use CreatesSales;
    use CreatesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimit::reset();
    }

    public function test_every_public_page_renders_without_a_session(): void
    {
        foreach (['/', '/about', '/services', '/doctors', '/book', '/contact', '/faq', '/privacy'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_the_sitemap_starts_with_the_xml_prolog(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringStartsWith(
            '<?xml version="1.0" encoding="UTF-8"?>',
            (string) $response->getContent(),
            'The sitemap must start with the XML prolog.',
        );
    }

    public function test_no_template_contains_a_raw_xml_prolog(): void
    {
        // A literal `<?xml` inside a template is parsed as a SHORT OPEN TAG
        // when `short_open_tag` is On (the PHP default when no php.ini is
        // loaded) and the whole page becomes a parse error. The prolog must
        // be emitted from PHP as a string.
        $offenders = [];

        foreach (glob(resource_path('views').'/**/*.blade.php') ?: [] as $file) {
            if (preg_match('/<\?xml/i', (string) file_get_contents($file)) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'A template contains a raw `<?xml` prolog.');
    }

    public function test_the_sitemap_lists_the_eight_public_urls(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $body = $response->getContent();
        $base = Seo::siteBaseUrl();

        $this->assertStringContainsString('<urlset', $body);

        foreach (['/', '/about', '/services', '/doctors', '/book', '/contact', '/faq', '/privacy'] as $path) {
            $this->assertStringContainsString('<loc>'.$base.$path.'</loc>', $body);
        }
    }

    public function test_the_robots_file_blocks_the_private_paths(): void
    {
        $response = $this->get('/robots.txt')->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString('User-agent: *', $body);
        $this->assertStringContainsString('Allow: /', $body);
        $this->assertStringContainsString('Disallow: /login, /api/, /settings', $body);
        $this->assertStringContainsString('Sitemap:', $body);
    }

    public function test_the_public_service_catalogue_never_shows_a_price(): void
    {
        $this->createService(['name' => 'Eye Consultation', 'current_price' => '500.00', 'is_public' => true]);

        $response = $this->get('/services')->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString('Eye Consultation', $body);
        $this->assertStringNotContainsString('500.00', $body);
        $this->assertStringNotContainsString('ETB 500', $body);
    }

    public function test_only_public_and_active_services_are_listed(): void
    {
        $this->createService(['name' => 'Public Service', 'is_public' => true, 'active' => true]);
        $this->createService(['name' => 'Hidden Service', 'is_public' => false, 'active' => true]);
        $this->createService(['name' => 'Inactive Service', 'is_public' => true, 'active' => false]);

        $body = $this->get('/services')->getContent();

        $this->assertStringContainsString('Public Service', $body);
        $this->assertStringNotContainsString('Hidden Service', $body);
        $this->assertStringNotContainsString('Inactive Service', $body);
    }

    public function test_the_doctors_feed_uses_the_allow_list_only(): void
    {
        $doctor = Staff::factory()->create([
            'full_name' => 'Dr. Published',
            'is_doctor' => true,
            'show_on_website' => true,
            'active' => true,
            'phone' => '+251911223344',
        ]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $doctors = $response->json('doctors');

        $this->assertCount(1, $doctors);

        $keys = array_keys($doctors[0]);
        sort($keys);

        $this->assertSame(Projection::PUBLIC_DOCTOR_FIELDS, $keys);
        $this->assertSame([], Projection::findForbiddenFields($doctors));
        $this->assertStringNotContainsString('+251911223344', (string) $response->getContent());
    }

    public function test_unpublished_doctors_are_not_in_the_feed(): void
    {
        Staff::factory()->create(['is_doctor' => true, 'show_on_website' => false, 'active' => true]);
        Staff::factory()->create(['is_doctor' => true, 'show_on_website' => true, 'active' => false]);
        Staff::factory()->create(['is_doctor' => false, 'show_on_website' => true, 'active' => true]);

        $response = $this->getJson('/api/public/doctors')->assertOk();

        $this->assertSame([], $response->json('doctors'));
    }

    public function test_the_doctors_feed_sets_a_public_cache_header(): void
    {
        $response = $this->getJson('/api/public/doctors')->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
    }

    public function test_the_service_image_route_is_gated_by_the_publication_flag(): void
    {
        $hidden = $this->createService(['is_public' => false]);
        $published = $this->createService(['is_public' => true]);

        $this->get("/api/public/services/{$hidden->id}/image")->assertNotFound();
        $this->get("/api/public/services/{$published->id}/image")->assertNotFound();
    }

    public function test_the_feedback_is_silently_dropped_when_the_honeypot_is_filled(): void
    {
        $response = $this->postJson('/api/public/feedback', [
            'name' => 'Bot',
            'message' => 'Spam',
            'consent' => true,
            'website' => 'http://spam.example',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'id' => null]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertSame(0, Feedback::query()->count());
    }

    public function test_the_feedback_is_stored_hidden_and_audited(): void
    {
        $response = $this->withHeader('x-forwarded-for', '203.0.113.7')
            ->postJson('/api/public/feedback', [
                'name' => 'Real Visitor',
                'email' => 'Visitor@Example.com',
                'rating' => '5',
                'message' => 'A very good visit.',
                'consent' => true,
            ]);

        $response->assertOk();

        $feedback = Feedback::query()->firstOrFail();

        $this->assertSame('Real Visitor', (string) $feedback->name);
        $this->assertSame('visitor@example.com', (string) $feedback->email);
        $this->assertSame(5, $feedback->rating);
        $this->assertFalse((bool) $feedback->show_on_website);
        $this->assertSame('public-website', (string) $feedback->created_by);
        $this->assertSame(hash('sha256', '203.0.113.7'), (string) $feedback->ip_hash);

        $audit = AuditLog::query()->where('action', 'FEEDBACK_SUBMITTED')->firstOrFail();

        $this->assertSame('WEBSITE', $audit->new_json['source']);
        $this->assertSame(5, $audit->new_json['rating']);
        $this->assertNull($audit->ip);
    }

    public function test_the_feedback_requires_consent_and_a_message(): void
    {
        $this->postJson('/api/public/feedback', [
            'name' => 'Visitor',
            'message' => 'Hello',
            'consent' => false,
        ])->assertStatus(422)->assertJsonPath('fields.consent', 'consentRequired');

        $this->postJson('/api/public/feedback', [
            'name' => 'Visitor',
            'message' => '',
            'consent' => true,
        ])->assertStatus(422)->assertJsonPath('fields.message', 'required');
    }

    public function test_the_feedback_rate_limit_blocks_the_fourth_submission(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/public/feedback', [
                'name' => 'Visitor '.$i,
                'message' => 'Hello',
                'consent' => true,
            ])->assertOk();
        }

        $this->postJson('/api/public/feedback', [
            'name' => 'Visitor 4',
            'message' => 'Hello',
            'consent' => true,
        ])->assertStatus(429)->assertJsonPath('error', 'rateLimited');
    }

    public function test_only_the_owner_may_edit_the_site_content(): void
    {
        $reception = $this->createUser(['role' => Roles::RECEPTION]);
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($reception)->get('/dashboard/content')->assertRedirect('/403');
        $this->actingAs($reception->fresh())->post('/dashboard/content', [])->assertForbidden();

        $this->actingAs($owner)->get('/dashboard/content')->assertOk();
    }

    public function test_the_owner_saves_the_site_content_and_it_is_rendered(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/dashboard/content', [
            'hero_title_en' => 'Our own headline',
            'hero_title_am' => 'የእኛና ርዕስ',
            'contact_address_en' => 'Somewhere else',
        ])->assertRedirect();

        $this->assertDatabaseHas('site_content', [
            'key' => 'hero_title',
            'value_en' => 'Our own headline',
            'value_am' => 'የእኛና ርዕስ',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'SiteContent',
            'entity_id' => 'all',
            'action' => 'SITE_CONTENT_UPDATED',
        ]);

        $body = $this->get('/')->getContent();
        $this->assertStringContainsString('Our own headline', $body);
    }

    public function test_a_blank_amharic_value_falls_back_to_english(): void
    {
        SiteContent::create(['key' => 'hero_title', 'value_en' => 'English only', 'value_am' => '']);

        $body = $this->get('/?lang=am')->getContent();

        $this->assertStringContainsString('English only', $body);
    }

    public function test_the_announcement_banner_obeys_its_window(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $today = now()->format('Y-m-d');

        $this->actingAs($owner)->post('/dashboard/content', [
            'announcement_active' => 'true',
            'announcement_text_en' => 'We are open on Sunday',
            'announcement_text_am' => 'እሁን ቅዳሜ እንክፈታለን',
            'announcement_start_en' => $today,
            'announcement_end_en' => $today,
        ])->assertRedirect();

        $body = $this->get('/')->getContent();
        $this->assertStringContainsString('We are open on Sunday', $body);
        $this->assertStringContainsString('id="announcements"', $body);
    }

    public function test_an_inactive_announcement_is_hidden(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/dashboard/content', [
            'announcement_active' => 'false',
            'announcement_text_en' => 'Hidden banner',
            'announcement_text_am' => 'ደብብ',
        ])->assertRedirect();

        $body = $this->get('/')->getContent();

        $this->assertStringNotContainsString('Hidden banner', $body);
    }

    public function test_the_owner_publishes_and_deletes_a_feedback(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $reception = $this->createUser(['role' => Roles::RECEPTION]);

        $feedback = Feedback::create([
            'name' => 'Visitor',
            'email' => 'v@example.com',
            'rating' => 4,
            'text' => 'Nice visit',
            'language' => 'en',
            'show_on_website' => false,
            'ip_hash' => 'abc',
            'created_by' => 'public-website',
        ]);

        $this->actingAs($reception)->get('/dashboard/feedback')->assertRedirect('/403');
        $this->actingAs($reception->fresh())->post("/dashboard/feedback/{$feedback->id}/toggle", ['showOnWebsite' => 'true'])->assertForbidden();

        $this->actingAs($owner)->get('/dashboard/feedback')->assertOk();

        $this->actingAs($owner->fresh())->post("/dashboard/feedback/{$feedback->id}/toggle", ['showOnWebsite' => 'true'])->assertRedirect();

        $this->assertTrue((bool) $feedback->fresh()->show_on_website);
        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Feedback',
            'entity_id' => $feedback->id,
            'action' => 'FEEDBACK_WEBSITE_CHANGED',
        ]);

        $this->actingAs($owner->fresh())->post("/dashboard/feedback/{$feedback->id}/delete")->assertRedirect();

        $this->assertSame(0, Feedback::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Feedback',
            'entity_id' => $feedback->id,
            'action' => 'FEEDBACK_DELETED',
        ]);
    }

    public function test_a_published_feedback_appears_as_a_testimonial(): void
    {
        Feedback::create([
            'name' => 'Happy Patient',
            'text' => 'Wonderful service',
            'rating' => 5,
            'language' => 'en',
            'show_on_website' => true,
            'ip_hash' => 'abc',
            'created_by' => 'public-website',
        ]);

        $body = $this->get('/')->getContent();

        $this->assertStringContainsString('Happy Patient', $body);
        $this->assertStringContainsString('Wonderful service', $body);
    }

    public function test_an_unpublished_feedback_never_reaches_the_public_page(): void
    {
        Feedback::create([
            'name' => 'Hidden Patient',
            'text' => 'Secret feedback',
            'rating' => 1,
            'language' => 'en',
            'show_on_website' => false,
            'ip_hash' => 'abc',
            'created_by' => 'public-website',
        ]);

        $body = $this->get('/')->getContent();

        $this->assertStringNotContainsString('Hidden Patient', $body);
        $this->assertStringNotContainsString('Secret feedback', $body);
    }

    public function test_the_public_pages_never_leak_patient_data(): void
    {
        $this->createPatient(['full_name' => 'Secret Patient', 'phone_e164' => '+251911223344']);

        foreach (['/', '/about', '/services', '/doctors', '/book', '/contact', '/faq', '/privacy'] as $path) {
            $body = $this->get($path)->getContent();

            $this->assertStringNotContainsString('Secret Patient', $body);
            $this->assertStringNotContainsString('+251911223344', $body);
        }
    }

    public function test_the_content_defaults_are_complete(): void
    {
        $this->assertCount(30, Content::CONTENT_KEYS);
        $this->assertSame(Content::CONTENT_KEYS, array_keys(Content::DEFAULT_CONTENT));
    }
}
