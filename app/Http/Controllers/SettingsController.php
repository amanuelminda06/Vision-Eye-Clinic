<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The clinic settings (OWNER only) — a single form that saves every key.
 *
 * The 17 form keys are upserted in ONE transaction and audited as
 * SETTING_UPDATED with the full old/new maps (the diff keeps only the changed
 * fields).
 */
class SettingsController extends Controller
{
    /** The form keys, in the legacy order. */
    public const SETTING_KEYS = [
        'clinic_name',
        'currency',
        'timezone',
        'working_hours',
        'reminder_send_time',
        'reminder_send_by_offset_minutes',
        'followup_lead_days',
        'recall_months',
        'review_link',
        'sms_mode',
        'reception_can_send_campaigns',
        'campaign_max_recipients',
        'digest_enabled',
        'digest_email',
        'default_language',
        'group_sms_allow_for_filtered',
        'group_sms_max_recipients',
    ];

    /** GET /settings */
    public function index(): Response
    {
        return response()->view('settings.index', [
            'keys' => self::SETTING_KEYS,
            'values' => $this->currentValues(),
        ]);
    }

    /** POST /settings — save every key. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $before = $this->currentValues();
        $submitted = [];

        foreach (self::SETTING_KEYS as $key) {
            $value = $this->readValue($request, $key);

            $error = $this->validateValue($key, $value);

            if ($error !== null) {
                return back()->withErrors([$key => $error])->withInput();
            }

            $submitted[$key] = $value;
        }

        DB::transaction(function () use ($submitted, $actor): void {
            foreach ($submitted as $key => $value) {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'updated_by' => $actor->id],
                );
            }
        });

        Audit::write('Setting', 'settings', 'SETTING_UPDATED', $before, $submitted, [
            'id' => $actor->id,
            'name' => $actor->name,
        ]);

        return back()->with('status', __('common.saved'));
    }

    /**
     * The current settings as a key → value map.
     *
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $values = [];

        foreach (Setting::query()->pluck('value', 'key')->all() as $key => $value) {
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Reads one key from the request.
     */
    private function readValue(Request $request, string $key): mixed
    {
        if ($key === 'working_hours') {
            $hours = [];

            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
                $hours[$day] = [
                    'open' => (string) $request->input("working_hours.$day.open", '08:00'),
                    'close' => (string) $request->input("working_hours.$day.close", '17:00'),
                    'closed' => (string) $request->input("working_hours.$day.closed", 'false') === 'true',
                ];
            }

            return $hours;
        }

        if (in_array($key, [
            'reception_can_send_campaigns',
            'digest_enabled',
            'group_sms_allow_for_filtered',
        ], true)) {
            return (string) $request->input($key, 'false') === 'true';
        }

        if (in_array($key, [
            'reminder_send_by_offset_minutes',
            'followup_lead_days',
            'recall_months',
            'campaign_max_recipients',
            'group_sms_max_recipients',
        ], true)) {
            return (int) $request->input($key, 0);
        }

        return trim((string) $request->input($key, ''));
    }

    /**
     * Validates one value; returns the i18n error key or null.
     */
    private function validateValue(string $key, mixed $value): ?string
    {
        $timeRe = '/^([01]\d|2[0-3]):[0-5]\d$/';

        switch ($key) {
            case 'clinic_name':
                if ($value === '') {
                    return 'required';
                }

                return mb_strlen((string) $value) > 100 ? 'tooLong' : null;

            case 'currency':
                if ($value === '') {
                    return 'required';
                }

                return mb_strlen((string) $value) > 10 ? 'tooLong' : null;

            case 'timezone':
                return in_array((string) $value, timezone_identifiers_list(), true) ? null : 'invalidTimezone';

            case 'working_hours':
                foreach ((array) $value as $day => $hours) {
                    if (preg_match($timeRe, (string) $hours['open']) !== 1
                        || preg_match($timeRe, (string) $hours['close']) !== 1) {
                        return 'invalidTime';
                    }

                    if (! $hours['closed'] && self::minutes((string) $hours['close']) <= self::minutes((string) $hours['open'])) {
                        return 'endTimeAfterStart';
                    }
                }

                return null;

            case 'reminder_send_time':
                return preg_match($timeRe, (string) $value) === 1 ? null : 'invalidTime';

            case 'reminder_send_by_offset_minutes':
            case 'followup_lead_days':
            case 'recall_months':
                return (int) $value >= 1 ? null : 'positive';

            case 'review_link':
                if (mb_strlen((string) $value) > 500) {
                    return 'tooLong';
                }

                if ($value === '' || preg_match('#^https?://\S+$#', (string) $value) === 1) {
                    return null;
                }

                return 'invalidUrl';

            case 'sms_mode':
                return in_array($value, ['MANUAL_LINK', 'GATEWAY'], true) ? null : 'invalid';

            case 'campaign_max_recipients':
                $number = (int) $value;

                return ($number >= 1 && $number <= 300) ? null : 'tooHigh';

            case 'group_sms_max_recipients':
                $number = (int) $value;

                return ($number >= 1 && $number <= 100) ? null : 'tooHigh';

            case 'digest_email':
                if (mb_strlen((string) $value) > 200) {
                    return 'tooLong';
                }

                if ($value === '' || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $value) === 1) {
                    return null;
                }

                return 'invalidEmail';

            case 'default_language':
                return in_array($value, ['en', 'am'], true) ? null : 'invalid';
        }

        return null;
    }

    /** The minutes since midnight of an `HH:mm` string. */
    private static function minutes(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hour) * 60 + (int) $minute;
    }
}
