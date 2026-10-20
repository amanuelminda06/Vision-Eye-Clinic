<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\SiteContent;
use App\Support\Audit;
use App\Support\PublicSite\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CMS editor (OWNER only): the bilingual site content and the feedback
 * moderation.
 */
class SiteContentController extends Controller
{
    /** GET /dashboard/content — the site content editor. */
    public function index(): Response
    {
        $rows = SiteContent::query()->get(['key', 'value_en', 'value_am']);

        $current = [];
        foreach ($rows as $row) {
            $current[$row->key] = [
                'en' => (string) $row->value_en,
                'am' => (string) $row->value_am,
            ];
        }

        return response()->view('site.content-editor', [
            'keys' => Content::CONTENT_KEYS,
            'current' => $current,
        ]);
    }

    /** POST /dashboard/content — save every content key in one transaction. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $before = Content::resolveContent(
            SiteContent::query()->get(['key', 'value_en', 'value_am'])
                ->map(fn (SiteContent $row): array => [
                    'key' => (string) $row->key,
                    'valueEn' => (string) $row->value_en,
                    'valueAm' => (string) $row->value_am,
                ])->all(),
        );

        $submitted = [];

        foreach (Content::CONTENT_KEYS as $key) {
            $en = trim((string) $request->input($key.'_en', ''));
            $am = trim((string) $request->input($key.'_am', ''));

            if (mb_strlen($en) > 5000 || mb_strlen($am) > 5000) {
                return back()->withErrors([$key => 'tooLong'])->withInput();
            }

            $submitted[$key] = ['en' => $en, 'am' => $am];
        }

        // The announcement flag is a single checkbox, mirrored into the `en`
        // column only.
        $submitted['announcement_active'] = [
            'en' => (string) $request->input('announcement_active', '') === 'true' ? 'true' : 'false',
            'am' => '',
        ];

        DB::transaction(function () use ($submitted, $actor): void {
            foreach ($submitted as $key => $value) {
                SiteContent::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value_en' => $value['en'],
                        'value_am' => $value['am'],
                        'updated_by' => $actor->id,
                    ],
                );
            }
        });

        Audit::write('SiteContent', 'all', 'SITE_CONTENT_UPDATED', $before, $submitted, [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return back()->with('status', __('common.saved'));
    }

    /** GET /dashboard/feedback — the feedback moderation list (OWNER only). */
    public function feedback(Request $request): Response
    {
        $q = mb_substr((string) $request->query('q', ''), 0, 100);
        $websiteRaw = (string) $request->query('website', '');

        $query = Feedback::query()->orderByDesc('created_at')->take(200);

        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q);

            $query->where(function ($inner) use ($escaped): void {
                $inner->where('name', 'like', '%'.$escaped.'%')
                    ->orWhere('text', 'like', '%'.$escaped.'%')
                    ->orWhere('email', 'like', '%'.$escaped.'%');
            });
        }

        if ($websiteRaw === 'true') {
            $query->where('show_on_website', true);
        } elseif ($websiteRaw === 'false') {
            $query->where('show_on_website', false);
        }

        return response()->view('site.feedback-list', [
            'rows' => $query->get()->map(fn (Feedback $feedback): array => [
                'id' => $feedback->id,
                'name' => (string) $feedback->name,
                'email' => $feedback->email,
                'rating' => $feedback->rating,
                'text' => (string) $feedback->text,
                'language' => (string) $feedback->language,
                'showOnWebsite' => (bool) $feedback->show_on_website,
                'createdAt' => $feedback->created_at,
            ])->all(),
            'q' => $q,
            'website' => $websiteRaw,
        ]);
    }

    /** POST /dashboard/feedback/{id}/toggle — publish / unpublish. */
    public function toggleFeedback(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $feedback = Feedback::find($id);

        if ($feedback === null) {
            return back()->with('actionError', 'unknownError');
        }

        $value = (string) $request->input('showOnWebsite', '') === 'true';
        $old = (bool) $feedback->show_on_website;

        if ($old === $value) {
            // No-op: nothing changed, so nothing is audited.
            return back();
        }

        $feedback->forceFill(['show_on_website' => $value])->save();

        Audit::write('Feedback', $id, 'FEEDBACK_WEBSITE_CHANGED',
            ['showOnWebsite' => $old],
            ['showOnWebsite' => $value],
            ['id' => $actor->id, 'name' => $actor->name],
        );

        return back();
    }

    /** POST /dashboard/feedback/{id}/delete — delete a feedback row. */
    public function destroyFeedback(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $feedback = Feedback::find($id);

        if ($feedback === null) {
            return back()->with('actionError', 'unknownError');
        }

        $snapshot = [
            'name' => (string) $feedback->name,
            'showOnWebsite' => (bool) $feedback->show_on_website,
        ];

        $feedback->delete();

        Audit::write('Feedback', $id, 'FEEDBACK_DELETED', $snapshot, null, [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return back();
    }
}
