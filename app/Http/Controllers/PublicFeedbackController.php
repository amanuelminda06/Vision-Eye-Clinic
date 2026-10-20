<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Support\Audit;
use App\Support\Ip;
use App\Support\PublicSite\FeedbackValidation;
use App\Support\RateLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public feedback intake, ported from
 * src/app/api/public/feedback/route.ts.
 *
 * Order: rate limit → JSON parse → HONEYPOT on the raw body (silent drop) →
 * schema → create. A new row always starts HIDDEN — the OWNER publishes it.
 */
class PublicFeedbackController extends Controller
{
    /** Feedback spam guard: 3 submissions per hour per IP. */
    public const RATE_MAX = 3;

    public const RATE_WINDOW_MS = 60 * 60_000;

    /** `createdBy` marker — nobody is signed in behind a feedback form. */
    public const PUBLIC_WEBSITE_ACTOR = 'public-website';

    public function __invoke(Request $request): JsonResponse
    {
        $ip = Ip::clientIp($request->header('x-forwarded-for'));

        if (! RateLimit::check('public-feedback:'.($ip ?? 'unknown'), self::RATE_MAX, self::RATE_WINDOW_MS)) {
            return response()->json(['ok' => false, 'error' => 'rateLimited'], 429);
        }

        $raw = (string) $request->getContent();
        $body = json_decode($raw, true);

        if ($raw === '' || json_last_error() !== JSON_ERROR_NONE || ! is_array($body)) {
            return response()->json(['ok' => false, 'error' => 'invalidJson'], 400);
        }

        // The honeypot is checked on the RAW body BEFORE the schema: a bot
        // gets a silent 200 and learns nothing.
        if (is_string($body['website'] ?? null) && trim($body['website']) !== '') {
            return response()->json(['ok' => true, 'id' => null])
                ->header('Cache-Control', 'no-store');
        }

        $validated = FeedbackValidation::feedbackForm($body);

        if ($validated['fields'] !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'validationError',
                'fields' => $validated['fields'],
            ], 422);
        }

        $values = $validated['values'];

        try {
            $feedback = Feedback::create([
                'name' => $values['name'],
                'email' => $values['email'],
                'rating' => $values['rating'],
                'text' => $values['message'],
                'language' => $values['language'],
                // New feedback is never public.
                'show_on_website' => false,
                'ip_hash' => Ip::hashClientIp($ip),
                'created_by' => self::PUBLIC_WEBSITE_ACTOR,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'unknownError'], 500);
        }

        Audit::write('Feedback', $feedback->id, 'FEEDBACK_SUBMITTED', null, [
            'source' => 'WEBSITE',
            'rating' => $feedback->rating,
            'language' => $feedback->language,
            'ipHash' => $feedback->ip_hash,
        ], null, null);

        return response()->json(['ok' => true, 'id' => $feedback->id])
            ->header('Cache-Control', 'no-store');
    }
}
