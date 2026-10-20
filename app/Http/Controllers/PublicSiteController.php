<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ClinicTime;
use App\Support\Hr\HrQueries;
use App\Support\PublicSite\Content;
use App\Support\PublicSite\Format;
use App\Support\PublicSite\PublicQueries;
use App\Support\PublicSite\Seo;
use App\Support\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The PUBLIC marketing site. No session, no patient data, no prices.
 */
class PublicSiteController extends Controller
{
    /** GET / — the home page. */
    public function home(Request $request): Response
    {
        $locale = app()->getLocale();
        $tz = Settings::timezone();
        $now = ClinicTime::nowUtc();

        $content = PublicQueries::getSiteContent();
        $services = PublicQueries::getPublicServices();
        $doctors = HrQueries::getPublicDoctorStatus($now, $tz);

        $onDuty = array_values(array_filter(
            $doctors,
            fn (array $doctor): bool => $doctor['status'] === 'ON_DUTY_NOW',
        ));

        $shown = $onDuty !== [] ? $onDuty : $doctors;

        $testimonials = array_slice(
            array_merge(PublicQueries::getPublicFeedbackTestimonials(), $this->manualTestimonials($content, $locale)),
            0,
            3,
        );

        return response()->view('site.home', [
            'content' => $content,
            'services' => array_slice($services, 0, 6),
            'doctors' => array_slice($shown, 0, 4),
            'hasDoctors' => $shown !== [],
            'announcements' => Content::parseItems(
                Content::pick($locale, $content['announcements_items']),
                Content::isAnnouncementItem(...),
            ),
            'testimonials' => $testimonials,
            'announcementVisible' => Content::isAnnouncementVisible($content, $now, $tz),
            'seo' => Seo::pageMetadata(
                __('site.meta.homeTitle'),
                __('site.meta.homeDescription'),
                '/',
                true,
            ),
        ]);
    }

    /** GET /about */
    public function about(Request $request): Response
    {
        return response()->view('site.about', [
            'content' => PublicQueries::getSiteContent(),
            'seo' => Seo::pageMetadata(
                __('site.meta.aboutTitle'),
                __('site.meta.aboutDescription'),
                '/about',
            ),
        ]);
    }

    /** GET /services — the public catalogue, grouped by category. */
    public function services(Request $request): Response
    {
        $services = PublicQueries::getPublicServices();

        $grouped = [];
        foreach ($services as $service) {
            $grouped[$service['category']][] = $service;
        }

        return response()->view('site.services', [
            'grouped' => $grouped,
            'seo' => Seo::pageMetadata(
                __('site.meta.servicesTitle'),
                __('site.meta.servicesDescription'),
                '/services',
            ),
        ]);
    }

    /** GET /doctors — the published doctors + duty badges. */
    public function doctors(Request $request): Response
    {
        $tz = Settings::timezone();
        $now = ClinicTime::nowUtc();

        $content = PublicQueries::getSiteContent();
        $locale = app()->getLocale();

        $intro = Content::pick($locale, $content['doctors_intro']);

        return response()->view('site.doctors', [
            'doctors' => HrQueries::getPublicDoctorStatus($now, $tz),
            'intro' => $intro !== '' ? $intro : __('site.doctors.intro'),
            'seo' => Seo::pageMetadata(
                __('site.meta.doctorsTitle'),
                __('site.meta.doctorsDescription'),
                '/doctors',
            ),
        ]);
    }

    /** GET /book — the booking form. */
    public function book(Request $request): Response
    {
        $tz = Settings::timezone();
        $todayKey = ClinicTime::clinicDateKey(ClinicTime::nowUtc(), $tz);

        return response()->view('site.book', [
            'services' => PublicQueries::getPublicServices(),
            'minDate' => $todayKey,
            'maxDate' => ClinicTime::dateKeyAdd($todayKey, 180),
            'seo' => Seo::pageMetadata(
                __('site.meta.bookTitle'),
                __('site.meta.bookDescription'),
                '/book',
            ),
        ]);
    }

    /** GET /contact — the contact card, hours and the feedback form. */
    public function contact(Request $request): Response
    {
        $content = PublicQueries::getSiteContent();
        $profile = PublicQueries::getClinicProfile();

        return response()->view('site.contact', [
            'content' => $content,
            'profile' => $profile,
            'hours' => $this->hoursLines(),
            'seo' => Seo::pageMetadata(
                __('site.meta.contactTitle'),
                __('site.meta.contactDescription'),
                '/contact',
            ),
        ]);
    }

    /** GET /faq */
    public function faq(Request $request): Response
    {
        $content = PublicQueries::getSiteContent();
        $locale = app()->getLocale();

        return response()->view('site.faq', [
            'items' => Content::parseItems(
                Content::pick($locale, $content['faq_items']),
                Content::isFaqItem(...),
            ),
            'seo' => Seo::pageMetadata(
                __('site.meta.faqTitle'),
                __('site.meta.faqDescription'),
                '/faq',
            ),
        ]);
    }

    /** GET /privacy */
    public function privacy(Request $request): Response
    {
        $content = PublicQueries::getSiteContent();
        $locale = app()->getLocale();

        $body = Content::pick($locale, $content['privacy_body']);

        return response()->view('site.privacy', [
            'paragraphs' => array_values(array_filter(
                preg_split('/\n\s*\n/', $body) ?: [],
                fn (string $paragraph): bool => trim($paragraph) !== '',
            )),
            'seo' => Seo::pageMetadata(
                __('site.meta.privacyTitle'),
                __('site.meta.privacyDescription'),
                '/privacy',
            ),
        ]);
    }

    /** GET /sitemap.xml */
    public function sitemap(): Response
    {
        $base = Seo::siteBaseUrl();
        $now = gmdate('Y-m-d');

        $paths = ['/', '/about', '/services', '/doctors', '/book', '/contact', '/faq', '/privacy'];

        $xml = view('site.sitemap', ['base' => $base, 'paths' => $paths, 'now' => $now])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** GET /robots.txt */
    public function robots(): Response
    {
        $base = Seo::siteBaseUrl();

        $body = "User-agent: *\nAllow: /\nDisallow: /login, /api/, /settings\nSitemap: {$base}/sitemap.xml\nHost: {$base}\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * The manual (CMS) testimonials.
     *
     * @param  array<string, array{en: string, am: string}>  $content
     * @return list<array<string, mixed>>
     */
    private function manualTestimonials(array $content, string $locale): array
    {
        return Content::parseItems(
            Content::pick($locale, $content['testimonials_items']),
            Content::isTestimonialItem(...),
        );
    }

    /**
     * The opening-hours lines (Monday first).
     *
     * @return list<array{day: string, value: string}>
     */
    private function hoursLines(): array
    {
        $hours = Settings::get('working_hours', []);

        $labels = [
            'mon' => __('settings.dayMon'),
            'tue' => __('settings.dayTue'),
            'wed' => __('settings.dayWed'),
            'thu' => __('settings.dayThu'),
            'fri' => __('settings.dayFri'),
            'sat' => __('settings.daySat'),
            'sun' => __('settings.daySun'),
        ];

        return Format::workingHoursLines(is_array($hours) ? $hours : [], $labels, __('settings.closed'));
    }
}
