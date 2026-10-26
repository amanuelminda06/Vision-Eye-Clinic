@extends('layouts.app')

@section('title', __('settings.title'))

@php
    $value = function (string $key, $default = '') use ($values) {
        $current = $values[$key] ?? $default;

        return is_array($current) ? '' : (string) $current;
    };

    $tv = fn (?string $key) => $key === null ? null : field_error($key);

    $hours = $values['working_hours'] ?? [];
    $dayHours = fn (string $day): array => is_array($hours[$day] ?? null)
        ? $hours[$day]
        : ['open' => '08:00', 'close' => '17:00', 'closed' => $day === 'sun'];
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('settings.title') }}</h1>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        <form method="POST" action="{{ route('settings.store') }}" class="space-y-5">
            @csrf

            <section class="card space-y-3 p-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('settings.title') }}</h2>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label text-xs" for="clinic_name">{{ __('settings.clinicName') }}</label>
                        <input class="input-field text-sm" id="clinic_name" name="clinic_name" maxlength="100" value="{{ $value('clinic_name', 'Vision Eye Clinic') }}">
                        @error('clinic_name')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="currency">{{ __('settings.currency') }}</label>
                        <input class="input-field text-sm" id="currency" name="currency" maxlength="10" value="{{ $value('currency', 'ETB') }}">
                        @error('currency')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                </div>

                <div>
                    <label class="label text-xs" for="timezone">{{ __('settings.timezone') }}</label>
                    <input class="input-field text-sm" id="timezone" name="timezone" maxlength="64" value="{{ $value('timezone', 'Africa/Addis_Ababa') }}">
                    @error('timezone')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                </div>
            </section>

            <section class="card space-y-3 p-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('settings.workingHours') }}</h2>

                @foreach (['mon' => 'dayMon', 'tue' => 'dayTue', 'wed' => 'dayWed', 'thu' => 'dayThu', 'fri' => 'dayFri', 'sat' => 'daySat', 'sun' => 'daySun'] as $dayKey => $labelKey)
                    @php $day = $dayHours($dayKey); @endphp
                    <div class="grid grid-cols-1 items-end gap-2 sm:grid-cols-4">
                        <div>
                            <label class="label text-xs" for="hours-{{ $dayKey }}-open">{{ __('settings.'.$labelKey) }}</label>
                            <div class="flex items-center gap-2">
                                <input class="input-field w-28 text-sm" id="hours-{{ $dayKey }}-open" name="working_hours[{{ $dayKey }}][open]" type="time" value="{{ $day['open'] }}">
                                <span class="text-xs text-slate-500">{{ __('settings.close') }}</span>
                                <input class="input-field w-28 text-sm" name="working_hours[{{ $dayKey }}][close]" type="time" value="{{ $day['close'] }}">
                            </div>
                        </div>
                        <div class="sm:col-span-3 flex items-center pb-1">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="working_hours[{{ $dayKey }}][closed]" value="true" @checked($day['closed'])>
                                {{ __('settings.closed') }}
                            </label>
                        </div>
                    </div>
                @endforeach

                @error('working_hours')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </section>

            <section class="card space-y-3 p-4">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('messages.title') }}</h2>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label text-xs" for="reminder_send_time">{{ __('settings.reminderSendTime') }}</label>
                        <input class="input-field text-sm" id="reminder_send_time" name="reminder_send_time" type="time" value="{{ $value('reminder_send_time', '14:00') }}">
                        @error('reminder_send_time')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="reminder_send_by_offset_minutes">{{ __('settings.reminderOffset') }}</label>
                        <input class="input-field text-sm" id="reminder_send_by_offset_minutes" name="reminder_send_by_offset_minutes" type="number" min="1" value="{{ $value('reminder_send_by_offset_minutes', 60) }}">
                        @error('reminder_send_by_offset_minutes')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="followup_lead_days">{{ __('settings.followupLeadDays') }}</label>
                        <input class="input-field text-sm" id="followup_lead_days" name="followup_lead_days" type="number" min="1" value="{{ $value('followup_lead_days', 3) }}">
                        @error('followup_lead_days')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="recall_months">{{ __('settings.recallMonths') }}</label>
                        <input class="input-field text-sm" id="recall_months" name="recall_months" type="number" min="1" value="{{ $value('recall_months', 12) }}">
                        @error('recall_months')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="sms_mode">{{ __('settings.smsMode') }}</label>
                        <select class="input-field text-sm" id="sms_mode" name="sms_mode">
                            <option value="MANUAL_LINK" @selected($value('sms_mode', 'MANUAL_LINK') === 'MANUAL_LINK')>{{ __('settings.smsManualLink') }}</option>
                            <option value="GATEWAY" @selected($value('sms_mode') === 'GATEWAY')>{{ __('settings.smsGateway') }}</option>
                        </select>
                        @error('sms_mode')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="default_language">{{ __('settings.defaultLanguage') }}</label>
                        <select class="input-field text-sm" id="default_language" name="default_language">
                            <option value="en" @selected($value('default_language', 'en') === 'en')>English</option>
                            <option value="am" @selected($value('default_language') === 'am')>አማርኛ</option>
                        </select>
                        @error('default_language')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="review_link">{{ __('settings.reviewLink') }}</label>
                        <input class="input-field text-sm" id="review_link" name="review_link" maxlength="500" value="{{ $value('review_link') }}">
                        @error('review_link')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="digest_email">{{ __('settings.digestEmail') }}</label>
                        <input class="input-field text-sm" id="digest_email" name="digest_email" type="email" maxlength="200" value="{{ $value('digest_email') }}">
                        @error('digest_email')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="campaign_max_recipients">{{ __('settings.campaignMaxRecipients') }}</label>
                        <input class="input-field text-sm" id="campaign_max_recipients" name="campaign_max_recipients" type="number" min="1" max="300" value="{{ $value('campaign_max_recipients', 300) }}">
                        @error('campaign_max_recipients')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                    <div>
                        <label class="label text-xs" for="group_sms_max_recipients">{{ __('settings.groupSmsMaxRecipients') }}</label>
                        <input class="input-field text-sm" id="group_sms_max_recipients" name="group_sms_max_recipients" type="number" min="1" max="100" value="{{ $value('group_sms_max_recipients', 15) }}">
                        @error('group_sms_max_recipients')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="reception_can_send_campaigns" value="true" @checked(($values['reception_can_send_campaigns'] ?? false) === true)>
                        {{ __('settings.receptionCampaigns') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="digest_enabled" value="true" @checked(($values['digest_enabled'] ?? false) === true)>
                        {{ __('settings.digestEnabled') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="group_sms_allow_for_filtered" value="true" @checked(($values['group_sms_allow_for_filtered'] ?? false) === true)>
                        {{ __('settings.groupSmsAllowFiltered') }}
                    </label>
                </div>

                {{-- Opt-in features (both OFF by default) --}}
                <div class="space-y-3">
                    <div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="patient_id_photos_enabled" value="true" @checked(($values['patient_id_photos_enabled'] ?? false) === true)>
                            {{ __('settings.patientIdPhotosEnabled') }}
                        </label>
                        <p class="mt-1 text-xs text-slate-500">{{ __('settings.patientIdPhotosEnabledHint') }}</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="patient_sick_leave_enabled" value="true" @checked(($values['patient_sick_leave_enabled'] ?? false) === true)>
                            {{ __('settings.patientSickLeaveEnabled') }}
                        </label>
                        <p class="mt-1 text-xs text-slate-500">{{ __('settings.patientSickLeaveEnabledHint') }}</p>
                    </div>

                    <div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="auto_approve_appointment_requests" value="true" @checked(($values['auto_approve_appointment_requests'] ?? false) === true)>
                            {{ __('settings.autoApproveAppointmentRequests') }}
                        </label>
                        <p class="mt-1 text-xs text-slate-500">{{ __('settings.autoApproveAppointmentRequestsHint') }}</p>
                    </div>
                </div>
            </section>

            <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
        </form>
    </div>
@endsection
