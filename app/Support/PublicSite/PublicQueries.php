<?php

declare(strict_types=1);

namespace App\Support\PublicSite;

use App\Models\Feedback;
use App\Models\Service;
use App\Models\SiteContent;
use App\Support\Settings;

/**
 * The public-site data queries, ported from
 * src/modules/public-site/queries.ts.
 *
 * Every query selects ONLY the public columns — a price or a patient field can
 * never survive into a public payload (rules 1 and 2).
 */
final class PublicQueries
{
    /**
     * The public service catalogue (no prices).
     *
     * @return list<array<string, mixed>>
     */
    public static function getPublicServices(): array
    {
        $rows = Service::query()
            ->where('is_public', true)
            ->where('active', true)
            ->orderBy('display_order')
            ->orderBy('category')
            ->orderBy('name')
            ->get([
                'id', 'name', 'name_en', 'name_am', 'category', 'display_order', 'image_url',
                'public_description_en', 'public_description_am',
            ]);

        return $rows->map(fn (Service $service): array => Projection::toPublicService([
            'id' => $service->id,
            'name' => (string) $service->name,
            'nameEn' => (string) $service->name_en,
            'nameAm' => $service->name_am,
            'category' => (string) $service->category,
            'displayOrder' => (int) $service->display_order,
            'imageUrl' => $service->image_url,
            'descriptionEn' => $service->public_description_en,
            'descriptionAm' => $service->public_description_am,
        ]))->all();
    }

    /**
     * The published testimonials (newest first, max 12).
     *
     * @return list<array<string, mixed>>
     */
    public static function getPublicFeedbackTestimonials(): array
    {
        return Feedback::query()
            ->where('show_on_website', true)
            ->orderByDesc('created_at')
            ->take(12)
            ->get(['id', 'name', 'text', 'rating'])
            ->map(fn (Feedback $feedback): array => Projection::toPublicFeedback([
                'id' => $feedback->id,
                'name' => (string) $feedback->name,
                'text' => (string) $feedback->text,
                'rating' => $feedback->rating,
            ]))->all();
    }

    /**
     * The resolved CMS content.
     *
     * @return array<string, array{en: string, am: string}>
     */
    public static function getSiteContent(): array
    {
        $rows = SiteContent::query()
            ->get(['key', 'value_en', 'value_am'])
            ->map(fn (SiteContent $row): array => [
                'key' => (string) $row->key,
                'valueEn' => (string) $row->value_en,
                'valueAm' => (string) $row->value_am,
            ])->all();

        return Content::resolveContent($rows);
    }

    /**
     * The clinic profile (never prices or patients).
     *
     * @return array<string, mixed>
     */
    public static function getClinicProfile(): array
    {
        return [
            'name' => (string) Settings::get('clinic_name', 'Vision Eye Clinic'),
            'timezone' => Settings::timezone(),
            'workingHours' => Settings::get('working_hours', []),
            'reviewLink' => (string) Settings::get('review_link', ''),
            'defaultLanguage' => Settings::defaultLanguage(),
        ];
    }
}
