<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PublicSite\Content;
use App\Support\PublicSite\FeedbackValidation;
use App\Support\PublicSite\Format;
use App\Support\PublicSite\Projection;
use App\Support\PublicSite\Seo;
use PHPUnit\Framework\TestCase;

/**
 * The public-site pure logic, ported from the src/modules/public-site/*
 * gold-standard suites.
 */
class PublicSiteLogicTest extends TestCase
{
    public function test_the_content_pick_falls_back_to_english(): void
    {
        $value = ['en' => 'English', 'am' => 'አማርኛ'];

        $this->assertSame('አማርኛ', Content::pick('am', $value));
        $this->assertSame('English', Content::pick('en', $value));
        $this->assertSame('English', Content::pick('am', ['en' => 'English', 'am' => '']));
    }

    public function test_the_item_parsing_is_defensive(): void
    {
        $this->assertSame([], Content::parseItems('not json', Content::isFaqItem(...)));
        $this->assertSame([], Content::parseItems('{"a":1}', Content::isFaqItem(...)));

        $items = Content::parseItems(
            '[{"question":"Q","answer":"A"},{"question":1,"answer":"A"},{"nope":true}]',
            Content::isFaqItem(...),
        );

        $this->assertCount(1, $items);
        $this->assertSame('Q', $items[0]['question']);
    }

    public function test_the_content_resolution_backfills_a_blank_amharic_value(): void
    {
        $map = Content::resolveContent([
            ['key' => 'hero_title', 'valueEn' => 'DB title', 'valueAm' => ''],
        ]);

        $this->assertSame('DB title', $map['hero_title']['en']);
        $this->assertSame('DB title', $map['hero_title']['am']);

        // Untouched keys keep the defaults.
        $this->assertSame(Content::DEFAULT_CONTENT['hero_subtitle'], $map['hero_subtitle']);
    }

    public function test_the_announcement_window_is_inclusive(): void
    {
        $now = new \DateTimeImmutable('2026-10-08T09:00:00Z');
        $tz = 'Africa/Addis_Ababa';

        $base = Content::DEFAULT_CONTENT;

        // The default text is blank → hidden.
        $this->assertFalse(Content::isAnnouncementVisible($base, $now, $tz));

        $active = array_replace($base, [
            'announcement_active' => ['en' => 'true', 'am' => 'true'],
            'announcement_text' => ['en' => 'Hello', 'am' => 'ሰላም'],
        ]);

        $this->assertTrue(Content::isAnnouncementVisible($active, $now, $tz));

        $withStart = array_replace($active, ['announcement_start' => ['en' => '2026-10-01', 'am' => '']]);
        $this->assertTrue(Content::isAnnouncementVisible($withStart, $now, $tz));

        $pastEnd = array_replace($active, ['announcement_end' => ['en' => '2026-10-01', 'am' => '']]);
        $this->assertFalse(Content::isAnnouncementVisible($pastEnd, $now, $tz));

        $futureStart = array_replace($active, ['announcement_start' => ['en' => '2026-10-15', 'am' => '']]);
        $this->assertFalse(Content::isAnnouncementVisible($futureStart, $now, $tz));
    }

    public function test_the_public_service_projection_drops_the_price(): void
    {
        $projected = Projection::toPublicService([
            'id' => 'svc-1',
            'name' => 'Eye Consultation',
            'nameEn' => 'Eye Consultation',
            'nameAm' => null,
            'category' => 'consultation',
            'displayOrder' => 0,
            'imageUrl' => null,
            'descriptionEn' => 'A check-up',
            'descriptionAm' => null,
            'currentPrice' => '500.00',
            'price' => '500.00',
        ]);

        $keys = array_keys($projected);
        sort($keys);

        $this->assertSame(Projection::PUBLIC_SERVICE_FIELDS, $keys);
        $this->assertSame([], Projection::findForbiddenFields($projected));
        $this->assertStringNotContainsString('500', (string) json_encode($projected));
    }

    public function test_the_forbidden_field_scanner_walks_nested_payloads(): void
    {
        $found = Projection::findForbiddenFields([
            'doctors' => [['id' => 'd1', 'phone' => '+2519', 'nested' => ['salary' => 10]]],
            'price' => 5,
        ]);

        sort($found);

        $this->assertSame(['phone', 'price', 'salary'], $found);
    }

    public function test_the_public_feedback_projection_drops_the_private_fields(): void
    {
        $projected = Projection::toPublicFeedback([
            'id' => 'f1',
            'name' => 'Visitor',
            'text' => 'Nice',
            'rating' => 5,
            'email' => 'v@example.com',
            'ipHash' => 'abc',
            'showOnWebsite' => true,
            'createdBy' => 'public-website',
        ]);

        $keys = array_keys($projected);
        sort($keys);

        $this->assertSame(Projection::PUBLIC_FEEDBACK_FIELDS, $keys);
        $this->assertSame([], Projection::findForbiddenFields($projected));
    }

    public function test_no_forbidden_field_name_collides_with_an_allow_list(): void
    {
        $allowLists = array_merge(
            Projection::PUBLIC_SERVICE_FIELDS,
            Projection::PUBLIC_DOCTOR_FIELDS,
            Projection::PUBLIC_FEEDBACK_FIELDS,
        );

        $this->assertSame([], array_intersect(Projection::FORBIDDEN_PUBLIC_FIELDS, $allowLists));
    }

    public function test_the_tel_link_needs_six_digits(): void
    {
        $this->assertSame('tel:+251911223344', Format::telHref('+251 911 223 344'));
        $this->assertSame('tel:0911223344', Format::telHref('0911223344'));
        $this->assertNull(Format::telHref('123'));
        $this->assertNull(Format::telHref(''));
        $this->assertNull(Format::telHref(null));
    }

    public function test_the_whatsapp_and_telegram_links(): void
    {
        $this->assertSame('https://wa.me/251900000000', Format::whatsappHref('+251900000000'));
        $this->assertSame('https://wa.me/251900000000', Format::whatsappHref('https://wa.me/251900000000'));
        $this->assertNull(Format::whatsappHref(''));

        $this->assertSame('https://t.me/visioneye', Format::telegramHref('@visioneye'));
        $this->assertSame('https://t.me/visioneye', Format::telegramHref('visioneye'));
        $this->assertSame('https://t.me/visioneye', Format::telegramHref('https://t.me/visioneye'));
        $this->assertNull(Format::telegramHref(''));
    }

    public function test_the_working_hours_lines_start_on_monday(): void
    {
        $hours = [
            'mon' => ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            'sun' => ['open' => '08:00', 'close' => '12:00', 'closed' => true],
        ];

        $lines = Format::workingHoursLines($hours, ['mon' => 'Mon', 'sun' => 'Sun'], 'Closed');

        $this->assertCount(7, $lines);
        $this->assertSame('Mon', $lines[0]['day']);
        $this->assertSame('08:00–17:00', $lines[0]['value']);
        $this->assertSame('Sun', $lines[6]['day']);
        $this->assertSame('Closed', $lines[6]['value']);
    }

    public function test_the_service_image_url_routes_the_uploads(): void
    {
        $this->assertNull(Format::publicServiceImageUrl('svc-1', null));
        $this->assertSame(
            '/api/public/services/svc-1/image',
            Format::publicServiceImageUrl('svc-1', '/api/uploads/service-1.png'),
        );
        $url = Format::publicServiceImageUrl('svc-1', '/services/general.png');
        $this->assertNotNull($url);
        $this->assertStringContainsString('services/general.png', $url);
    }

    public function test_the_base_url_strips_the_trailing_slash(): void
    {
        putenv('PUBLIC_BASE_URL=https://vision.example.com/');
        $_ENV['PUBLIC_BASE_URL'] = 'https://vision.example.com/';

        $this->assertSame('https://vision.example.com', Seo::siteBaseUrl());
    }

    public function test_the_page_metadata_carries_the_canonical_and_the_alternates(): void
    {
        putenv('PUBLIC_BASE_URL=https://vision.example.com/');
        $_ENV['PUBLIC_BASE_URL'] = 'https://vision.example.com/';

        $metadata = Seo::pageMetadata('About us', 'About the clinic', '/about');

        $this->assertSame('/about', $metadata['canonical']);
        $this->assertSame('/about?lang=en', $metadata['languages']['en']);
        $this->assertSame('/about?lang=am', $metadata['languages']['am']);
        $this->assertSame('/about', $metadata['languages']['x-default']);
        $this->assertSame('https://vision.example.com/about', $metadata['openGraph']['url']);
        $this->assertSame('summary_large_image', $metadata['twitter']['card']);
    }

    public function test_the_feedback_rating_coercion(): void
    {
        $this->assertSame(5, FeedbackValidation::feedbackForm([
            'name' => 'A', 'message' => 'B', 'consent' => true, 'rating' => '5',
        ])['values']['rating']);

        $this->assertSame(4, FeedbackValidation::feedbackForm([
            'name' => 'A', 'message' => 'B', 'consent' => true, 'rating' => 4,
        ])['values']['rating']);

        $this->assertNull(FeedbackValidation::feedbackForm([
            'name' => 'A', 'message' => 'B', 'consent' => true, 'rating' => '',
        ])['values']['rating']);
    }

    public function test_the_feedback_email_is_lowercased(): void
    {
        $result = FeedbackValidation::feedbackForm([
            'name' => 'A', 'message' => 'B', 'consent' => true, 'email' => '  PATIENT@Example.COM ',
        ]);

        $this->assertSame('patient@example.com', $result['values']['email']);
    }

    public function test_the_feedback_rejects_a_bad_rating_or_email(): void
    {
        foreach (['6', '0', 'great'] as $rating) {
            $result = FeedbackValidation::feedbackForm([
                'name' => 'A', 'message' => 'B', 'consent' => true, 'rating' => $rating,
            ]);

            $this->assertSame('invalidRating', $result['fields']['rating'], $rating);
        }

        $result = FeedbackValidation::feedbackForm([
            'name' => 'A', 'message' => 'B', 'consent' => true, 'email' => 'not-an-email',
        ]);

        $this->assertSame('invalidEmail', $result['fields']['email']);
    }
}
