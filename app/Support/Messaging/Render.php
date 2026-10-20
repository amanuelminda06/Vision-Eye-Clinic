<?php

declare(strict_types=1);

namespace App\Support\Messaging;

use App\Support\ClinicTime;

/**
 * Per-task rendering, ported from src/modules/messaging/render.ts.
 */
final class Render
{
    /**
     * Body for a key+language, falling back to English, then to ''.
     *
     * @param  array<string, string>  $bodies  keyed by `KEY:lang`
     */
    public static function bodyFor(array $bodies, string $key, string $language): string
    {
        return $bodies[$key.':'.$language] ?? $bodies[$key.':en'] ?? '';
    }

    /**
     * Builds the variable map for one task from its source context.
     *
     * @param  array{patientName: string, language: string, clinicName: string, reviewLink: string, startsAt?: ?\DateTimeInterface, doctorName?: ?string, reopenDate?: ?string, timeZone: string}  $context
     * @return array<string, string>
     */
    public static function renderVariables(array $context): array
    {
        $variables = [
            'name' => $context['patientName'],
            'clinic' => $context['clinicName'],
            'review_link' => $context['reviewLink'],
        ];

        if (! empty($context['doctorName'])) {
            $variables['doctor'] = (string) $context['doctorName'];
        }

        if (! empty($context['reopenDate'])) {
            $variables['reopen_date'] = (string) $context['reopenDate'];
        }

        if (! empty($context['startsAt'])) {
            $variables['date'] = ClinicTime::formatInTz(
                $context['startsAt'],
                $context['timeZone'],
                ['weekday' => 'short', 'day' => 'numeric', 'month' => 'short'],
            );
            $variables['time'] = ClinicTime::clinicTimeKey($context['startsAt'], $context['timeZone']);
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function renderMessage(string $body, array $context): string
    {
        return Templates::renderTemplate($body, self::renderVariables($context));
    }

    /** The preferred language, falling back to English. */
    public static function resolveLanguage(?string $preferred): string
    {
        return Constants::isMessageLanguage($preferred) ? (string) $preferred : 'en';
    }
}
