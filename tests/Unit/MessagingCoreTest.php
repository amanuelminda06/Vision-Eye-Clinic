<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Messaging\Constants;
use App\Support\Messaging\Eligibility;
use App\Support\Messaging\GroupPlan;
use App\Support\Messaging\MessageHash;
use App\Support\Messaging\MsgRoles;
use App\Support\Messaging\Provider;
use App\Support\Messaging\Render;
use App\Support\Messaging\Templates;
use PHPUnit\Framework\TestCase;

/**
 * Messaging core (pure logic), ported from the src/modules/messaging/*
 * gold-standard suites.
 */
class MessagingCoreTest extends TestCase
{
    public function test_it_extracts_the_template_variables_in_order(): void
    {
        // De-duplicated, first-seen order.
        $this->assertSame(
            ['name', 'date'],
            Templates::templateVariables('Hello {name} on {date}, bye {name}'),
        );
    }

    public function test_it_flags_unknown_variables(): void
    {
        $this->assertSame([], Templates::unknownVariables('Hi {name} at {time}'));

        $unknown = Templates::unknownVariables('Hi {does_not_exist}');
        $this->assertContains('does_not_exist', $unknown);

        // An empty name counts as unknown.
        $this->assertContains('', Templates::unknownVariables('Hello {}'));

        // A lone brace is literal text — only `{...}` pairs are variables.
        $this->assertSame([], Templates::unknownVariables('a { b'));
    }

    public function test_the_body_validation_reports_the_first_problem(): void
    {
        $this->assertSame('messageRequired', Templates::validateMessageBody('   ')['error']);
        $this->assertSame('messageTooLong', Templates::validateMessageBody(str_repeat('x', 1001))['error']);
        $this->assertSame('unknownVariable', Templates::validateMessageBody('Hello {nope}')['error']);
        $this->assertTrue(Templates::validateMessageBody('Hello {name}')['ok']);
    }

    public function test_the_sensitive_scan_never_blocks(): void
    {
        $result = Templates::validateMessageBody('Dear {name}, your cataract surgery costs 5000 birr.');

        $this->assertTrue($result['ok']);
        $this->assertContains('cataract', $result['sensitive']);
        $this->assertContains('surgery', $result['sensitive']);

        $this->assertSame([], Templates::scanSensitiveContent('Please come for a check-up.'));
        $this->assertContains('glaucoma', Templates::scanSensitiveContent('You have GLAUCOMA'));
        $this->assertContains('ዋጋ', Templates::scanSensitiveContent('ዋጋው ብር ነው'));
    }

    public function test_the_rendering_drops_the_review_link_line_when_empty(): void
    {
        $body = "Hello {name}\nReview: {review_link}\nBye";

        $withLink = Templates::renderTemplate($body, ['name' => 'Abebe', 'review_link' => 'https://x.test']);
        $this->assertStringContainsString('Review: https://x.test', $withLink);

        $withoutLink = Templates::renderTemplate($body, ['name' => 'Abebe', 'review_link' => '']);
        $this->assertStringNotContainsString('{review_link}', $withoutLink);
        $this->assertStringNotContainsString('Review:', $withoutLink);
        $this->assertStringContainsString('Hello Abebe', $withoutLink);
    }

    public function test_the_rendering_replaces_unknown_and_missing_variables_with_nothing(): void
    {
        $rendered = Templates::renderTemplate('Hi {name}, {doctor}, {nope}', ['name' => 'Abebe']);

        $this->assertSame('Hi Abebe, , ', $rendered);
    }

    public function test_the_sms_counter_uses_the_gsm7_budget_for_latin(): void
    {
        $count = Templates::countSms('Hello');

        $this->assertSame('GSM-7', $count['encoding']);
        $this->assertSame(5, $count['length']);
        $this->assertSame(160, $count['perPart']);
        $this->assertSame(1, $count['parts']);
    }

    public function test_the_sms_counter_switches_to_unicode_for_amharic(): void
    {
        $count = Templates::countSms('ሰላም ልደት');

        $this->assertSame('Unicode', $count['encoding']);
        $this->assertSame(7, $count['length']);
        $this->assertSame(70, $count['perPart']);
        $this->assertSame(1, $count['parts']);
    }

    public function test_the_sms_counter_counts_the_extension_chars_as_two(): void
    {
        $count = Templates::countSms('{');

        $this->assertSame('GSM-7', $count['encoding']);
        $this->assertSame(2, $count['length']);
        $this->assertSame(1, $count['parts']);
    }

    public function test_the_sms_counter_splits_long_messages(): void
    {
        $count = Templates::countSms(str_repeat('a', 200));

        $this->assertSame(2, $count['parts']);
        $this->assertSame(153, $count['perPart']);

        $empty = Templates::countSms('');
        $this->assertSame(0, $empty['length']);
        $this->assertSame(1, $empty['parts']);
        $this->assertSame(160, $empty['perPart']);
    }

    public function test_the_body_lookup_falls_back_to_english(): void
    {
        $bodies = ['APPT_CONFIRMATION:am' => 'am-body', 'APPT_CONFIRMATION:en' => 'en-body'];

        $this->assertSame('am-body', Render::bodyFor($bodies, 'APPT_CONFIRMATION', 'am'));
        $this->assertSame('en-body', Render::bodyFor($bodies, 'APPT_CONFIRMATION', 'fr'));
        $this->assertSame('', Render::bodyFor([], 'APPT_CONFIRMATION', 'en'));
    }

    public function test_the_language_resolution_falls_back_to_english(): void
    {
        $this->assertSame('am', Render::resolveLanguage('am'));
        $this->assertSame('en', Render::resolveLanguage('fr'));
        $this->assertSame('en', Render::resolveLanguage(null));
    }

    public function test_the_eligibility_precedence_is_fixed(): void
    {
        $this->assertSame('inactive', Eligibility::blockedReason([
            'patient' => ['active' => false, 'doNotMessage' => true, 'phoneE164' => 'bad'],
        ]));

        $this->assertSame('doNotMessage', Eligibility::blockedReason([
            'patient' => ['active' => true, 'doNotMessage' => true, 'phoneE164' => 'bad'],
        ]));

        $this->assertSame('invalidPhone', Eligibility::blockedReason([
            'patient' => ['active' => true, 'doNotMessage' => false, 'phoneE164' => 'bad'],
        ]));

        $this->assertSame('sourceCancelled', Eligibility::blockedReason([
            'patient' => ['active' => true, 'doNotMessage' => false, 'phoneE164' => '+251911223344'],
            'sourceStatus' => 'CANCELLED',
        ]));

        $this->assertNull(Eligibility::blockedReason([
            'patient' => ['active' => true, 'doNotMessage' => false, 'phoneE164' => '+251911223344'],
        ]));
    }

    public function test_the_group_batches_fill_earlier_batches_first(): void
    {
        $this->assertSame([15, 15, 10], GroupPlan::planBatches(40, 15));
        $this->assertSame([7], GroupPlan::planBatches(7, 15));
        $this->assertSame([], GroupPlan::planBatches(0, 15));
        $this->assertSame([], GroupPlan::planBatches(-3, 15));
        $this->assertSame([15, 15, 8], GroupPlan::planBatches(38, 15));
        $this->assertCount(10, GroupPlan::planBatches(10, 0));
        $this->assertSame([10], GroupPlan::planBatches(10, 1000));
        $this->assertCount(3, GroupPlan::planBatches(250, 100));
        $this->assertSame(GroupPlan::GROUP_BATCH_HARD_MAX, max(GroupPlan::planBatches(501, 1000)));
    }

    public function test_the_group_batch_size_resolution(): void
    {
        $this->assertSame(15, GroupPlan::resolveGroupBatchSize(null));
        $this->assertSame(15, GroupPlan::resolveGroupBatchSize('oops'));
        $this->assertSame(1, GroupPlan::resolveGroupBatchSize(0));
        $this->assertSame(100, GroupPlan::resolveGroupBatchSize(5000));
        $this->assertSame(15, GroupPlan::resolveGroupBatchSize(15.9));
    }

    public function test_the_message_hash_is_stable_and_8_hex_chars(): void
    {
        $body = 'Dear {name}, please come on {date}';

        $this->assertSame(MessageHash::messageBodyHash($body), MessageHash::messageBodyHash($body));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', MessageHash::messageBodyHash('x'));
        $this->assertNotSame(
            MessageHash::messageBodyHash('Message A'),
            MessageHash::messageBodyHash('Message B'),
        );
    }

    public function test_the_message_hash_ignores_case_and_spacing(): void
    {
        $this->assertSame(
            MessageHash::messageBodyHash('Hello   World'),
            MessageHash::messageBodyHash('hello world'),
        );
    }

    public function test_the_body_normalisation(): void
    {
        $this->assertSame('hello አለም', MessageHash::normalizeMessageBody('  Hello   አለም  '));
        $this->assertSame('', MessageHash::normalizeMessageBody(''));
    }

    public function test_the_auto_title_is_capped_at_40_characters(): void
    {
        $this->assertSame(
            'Appointment reminder',
            MessageHash::titleFromBody("Appointment reminder\nnext line"),
        );

        $long = MessageHash::titleFromBody(str_repeat('x', 100));
        $this->assertSame(40, mb_strlen($long));
        $this->assertTrue(str_ends_with($long, '…'));

        $this->assertSame('', MessageHash::titleFromBody(''));
    }

    public function test_the_sms_link_uses_the_platform_separator(): void
    {
        $this->assertSame(
            'sms:+251911111111?body=Hello',
            Provider::buildSmsLink('+251911111111', 'Hello'),
        );

        $this->assertSame(
            'sms:+251911111111&body=Hi',
            Provider::buildSmsLink('+251911111111', 'Hi', 'ios'),
        );

        $amharic = Provider::buildSmsLink('+251911111111', 'መልካም ልደት');
        $this->assertStringNotContainsString(' ', $amharic);
    }

    public function test_the_sms_link_refuses_an_empty_body(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Provider::buildSmsLink('+251911111111', '   ');
    }

    public function test_the_group_sms_link_joins_the_recipients(): void
    {
        $this->assertSame(
            'sms:+251911111111,+251922222222?body=Move%20to%203rd%20floor',
            Provider::buildGroupSmsLink(['+251911111111', '+251922222222'], 'Move to 3rd floor'),
        );

        $this->assertSame(
            'sms:+251911111111,+251922222222?body=hi',
            Provider::buildGroupSmsLink([' +251911111111 ', '  ', '+251922222222'], 'hi'),
        );
    }

    public function test_the_group_sms_link_refuses_no_recipients(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Provider::buildGroupSmsLink([], 'hi');
    }

    public function test_the_platform_detection(): void
    {
        $this->assertSame('ios', Provider::detectPlatform('Mozilla/5.0 (iPhone; CPU iPhone OS 17)'));
        $this->assertSame('android', Provider::detectPlatform('Mozilla/5.0 (Linux; Android 14)'));
        $this->assertSame('other', Provider::detectPlatform(''));
        $this->assertSame('other', Provider::detectPlatform(null));
    }

    public function test_the_provider_resolution(): void
    {
        $this->assertSame('MANUAL_LINK', Provider::getSmsProvider('MANUAL_LINK')['mode']);
        $this->assertSame('MANUAL_LINK', Provider::getSmsProvider(null)['mode']);
        $this->assertSame('GATEWAY', Provider::getSmsProvider('GATEWAY')['mode']);
        $this->assertTrue(Provider::getSmsProvider('GATEWAY')['supportsBulkIndividual']);
        $this->assertFalse(Provider::getSmsProvider('MANUAL_LINK')['supportsBulkIndividual']);
    }

    public function test_the_role_predicates(): void
    {
        $this->assertSame(['OWNER', 'RECEPTION'], MsgRoles::MESSAGE_ROLES);
        $this->assertSame(['OWNER'], MsgRoles::MESSAGE_OWNER_ROLES);

        $this->assertTrue(MsgRoles::canSendMessages('OWNER'));
        $this->assertTrue(MsgRoles::canSendMessages('RECEPTION'));
        $this->assertFalse(MsgRoles::canSendMessages('STAFF'));

        $this->assertTrue(MsgRoles::canEditTemplates('OWNER'));
        $this->assertFalse(MsgRoles::canEditTemplates('RECEPTION'));

        $this->assertTrue(MsgRoles::canSendCampaigns('OWNER', false));
        $this->assertFalse(MsgRoles::canSendCampaigns('RECEPTION', false));
        $this->assertTrue(MsgRoles::canSendCampaigns('RECEPTION', true));
        $this->assertFalse(MsgRoles::canSendCampaigns('STAFF', true));
    }

    public function test_the_constant_registries(): void
    {
        $this->assertSame([
            'name', 'date', 'time', 'doctor', 'clinic', 'review_link', 'reopen_date',
        ], Constants::TEMPLATE_VARIABLES);

        $this->assertCount(13, Constants::TEMPLATE_KEYS);
        $this->assertSame(['en', 'am'], Constants::MESSAGE_LANGUAGES);
        $this->assertSame(['inactive', 'doNotMessage', 'invalidPhone', 'sourceCancelled'], Constants::BLOCKED_REASONS);
        $this->assertSame(1000, Constants::MAX_TEMPLATE_BODY);
        $this->assertSame(300, Constants::MAX_SKIP_REASON);
        $this->assertSame(120, Constants::MAX_CAMPAIGN_TITLE);
    }
}
