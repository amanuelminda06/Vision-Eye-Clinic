<?php

declare(strict_types=1);

namespace App\Support\Messaging;

/**
 * All messaging registries and label maps, ported from
 * src/modules/messaging/constants.ts.
 */
final class Constants
{
    /** @var list<string> Placeholders a template body may contain. */
    public const TEMPLATE_VARIABLES = [
        'name', 'date', 'time', 'doctor', 'clinic', 'review_link', 'reopen_date',
    ];

    /** @var list<string> */
    public const MESSAGE_LANGUAGES = ['en', 'am'];

    /** @var list<string> */
    public const TEMPLATE_KEYS = [
        'APPT_REMINDER', 'APPT_CONFIRMATION', 'APPT_SAME_DAY', 'NO_SHOW',
        'SCHEDULE_CHANGE', 'FOLLOWUP_DUE', 'RECALL', 'GLASSES_READY',
        'THANK_YOU', 'BIRTHDAY', 'WELCOME', 'CLOSURE_NOTICE', 'CUSTOM',
    ];

    /** `__('messages.<key>')` for each template. */
    public const TEMPLATE_LABEL_KEYS = [
        'APPT_REMINDER' => 'tplApptReminder',
        'APPT_CONFIRMATION' => 'tplApptConfirmation',
        'APPT_SAME_DAY' => 'tplApptSameDay',
        'NO_SHOW' => 'tplNoShow',
        'SCHEDULE_CHANGE' => 'tplScheduleChange',
        'FOLLOWUP_DUE' => 'tplFollowupDue',
        'RECALL' => 'tplRecall',
        'GLASSES_READY' => 'tplGlassesReady',
        'THANK_YOU' => 'tplThankYou',
        'BIRTHDAY' => 'tplBirthday',
        'WELCOME' => 'tplWelcome',
        'CLOSURE_NOTICE' => 'tplClosureNotice',
        'CUSTOM' => 'tplCustom',
    ];

    /** `__('messages.<key>')` for each task status. */
    public const MESSAGE_STATUS_LABEL_KEYS = [
        'PENDING' => 'statusPending',
        'OPENED' => 'statusOpened',
        'SENT' => 'statusSent',
        'SKIPPED' => 'statusSkipped',
        'EXPIRED' => 'statusExpired',
        'CANCELLED' => 'statusCancelled',
        'BLOCKED' => 'statusBlocked',
    ];

    /** `__('messages.<key>')` for each source. */
    public const MESSAGE_SOURCE_LABEL_KEYS = [
        'APPOINTMENT' => 'sourceAppointment',
        'FOLLOWUP' => 'sourceFollowUp',
        'BIRTHDAY' => 'sourceBirthday',
        'RECALL' => 'sourceRecall',
        'CAMPAIGN' => 'sourceCampaign',
        'MANUAL' => 'sourceManual',
    ];

    /** `__('messages.<key>')` for each campaign status. */
    public const CAMPAIGN_STATUS_LABEL_KEYS = [
        'DRAFT' => 'campaignDraft',
        'ACTIVE' => 'campaignActive',
        'COMPLETED' => 'campaignCompleted',
        'CANCELLED' => 'campaignCancelled',
    ];

    /** @var list<string> */
    public const CAMPAIGN_DELIVERY_MODES = ['INDIVIDUAL', 'GROUP'];

    /** `__('messages.<key>')` for each delivery mode. */
    public const DELIVERY_MODE_LABEL_KEYS = [
        'INDIVIDUAL' => 'deliveryIndividual',
        'GROUP' => 'deliveryGroup',
    ];

    /** `__('messages.<key>')` for each batch status. */
    public const BATCH_STATUS_LABEL_KEYS = [
        'PENDING' => 'batchPending',
        'SENT' => 'batchSent',
        'SKIPPED' => 'batchSkipped',
    ];

    /** @var list<string> */
    public const LIBRARY_CATEGORIES = ['REMINDER', 'GREETING', 'ANNOUNCEMENT', 'FOLLOWUP', 'OTHER'];

    /** `__('messages.<key>')` for each library category. */
    public const LIBRARY_CATEGORY_LABEL_KEYS = [
        'REMINDER' => 'libReminder',
        'GREETING' => 'libGreeting',
        'ANNOUNCEMENT' => 'libAnnouncement',
        'FOLLOWUP' => 'libFollowup',
        'OTHER' => 'libOther',
    ];

    /** Why group delivery is blocked by default — `messages.groupBlock.*` keys. */
    public const GROUP_BLOCK_LABEL_KEYS = [
        'service' => 'groupBlockService',
        'tag' => 'groupBlockTag',
        'category' => 'groupBlockCategory',
        'upcoming' => 'groupBlockUpcoming',
        'purchase' => 'groupBlockPurchase',
        'filtered' => 'groupBlockFiltered',
    ];

    /** @var list<string> Eligibility reasons (raw keys). */
    public const BLOCKED_REASONS = ['inactive', 'doNotMessage', 'invalidPhone', 'sourceCancelled'];

    /** `__('messages.<key>')` for each blocked reason. */
    public const BLOCKED_REASON_LABEL_KEYS = [
        'inactive' => 'blockedInactive',
        'doNotMessage' => 'blockedDoNotMessage',
        'invalidPhone' => 'blockedInvalidPhone',
        'sourceCancelled' => 'blockedSourceCancelled',
    ];

    /** @var list<string> */
    public const CAMPAIGN_LANGUAGE_MODES = ['PATIENT', 'FIXED'];

    /** @var list<string> */
    public const CAMPAIGN_TAG_MODES = ['ANY', 'ALL'];

    /** @var list<string> */
    public const CAMPAIGN_UPCOMING_MODES = ['ANY', 'HAS', 'NONE'];

    public const MAX_TEMPLATE_BODY = 1000;

    public const MAX_SKIP_REASON = 300;

    public const MAX_CAMPAIGN_TITLE = 120;

    public const MAX_CAMPAIGN_MESSAGE = 1000;

    /** Non-clinical reminder shown above the template editor. */
    public const TEMPLATE_SAFETY_NOTE_KEY = 'templateSafetyNote';

    /** GSM-7 basic character set (the SMS length counter). */
    public const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        .'¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** GSM-7 extension characters (each costs 2 septets). */
    public const GSM7_EXTENSION = "\f^{}\\[~]|€";

    public static function isMessageLanguage(mixed $value): bool
    {
        return $value === 'en' || $value === 'am';
    }

    public static function isTemplateKey(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::TEMPLATE_KEYS, true);
    }

    public static function isBlockedReason(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::BLOCKED_REASONS, true);
    }
}
