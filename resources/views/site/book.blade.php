@extends('layouts.site')

@section('content')
    <div class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('site.book.title') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('site.book.intro') }}</p>

        <form id="booking-form" class="card mt-6 space-y-4 p-5">
            <div>
                <label class="label" for="book-name">{{ __('site.book.name') }} <span class="text-red-500">*</span></label>
                <input class="input-field" id="book-name" name="name" maxlength="100" required
                       placeholder="{{ __('site.book.namePlaceholder') }}">
                <p class="mt-1 text-xs text-red-600" data-error="name" role="alert"></p>
            </div>

            <div>
                <label class="label" for="book-phone">{{ __('site.book.phone') }} <span class="text-red-500">*</span></label>
                <input class="input-field" id="book-phone" name="phone" type="tel" maxlength="32" required
                       placeholder="{{ __('site.book.phonePlaceholder') }}">
                <p class="mt-1 text-xs text-slate-500">{{ __('site.book.phoneHint') }}</p>
                <p class="mt-1 text-xs text-red-600" data-error="phone" role="alert"></p>
            </div>

            <div>
                <label class="label" for="book-date">{{ __('site.book.preferredDate') }} <span class="text-red-500">*</span></label>
                <input class="input-field" id="book-date" name="preferredDate" type="date" required
                       min="{{ $minDate }}" max="{{ $maxDate }}">
                <p class="mt-1 text-xs text-red-600" data-error="preferredDate" role="alert"></p>
            </div>

            <div>
                <label class="label" for="book-window">{{ __('site.book.timeWindow') }}</label>
                <select class="input-field" id="book-window" name="preferredTimeWindow">
                    <option value="any">{{ __('site.book.windowAny') }}</option>
                    <option value="morning">{{ __('site.book.windowMorning') }}</option>
                    <option value="afternoon">{{ __('site.book.windowAfternoon') }}</option>
                    <option value="evening">{{ __('site.book.windowEvening') }}</option>
                </select>
                <p class="mt-1 text-xs text-red-600" data-error="preferredTimeWindow" role="alert"></p>
            </div>

            <div>
                <label class="label" for="book-service">{{ __('site.book.serviceInterest') }}</label>
                <select class="input-field" id="book-service" name="serviceInterest">
                    <option value="">{{ __('site.book.serviceNone') }}</option>
                    @foreach ($services as $service)
                        <option value="{{ $service['nameEn'] ?: $service['name'] }}">{{ $service['nameEn'] ?: $service['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="book-message">{{ __('site.book.message') }} <span class="text-xs text-slate-500">({{ __('site.book.messageOptional') }})</span></label>
                <textarea class="input-field" id="book-message" name="message" rows="3" maxlength="500"
                          placeholder="{{ __('site.book.messagePlaceholder') }}"></textarea>
                <p class="mt-1 text-xs text-slate-500">{{ __t('site.book.messageHint', ['max' => 500]) }}</p>
                <p class="mt-1 text-xs text-red-600" data-error="message" role="alert"></p>
            </div>

            {{-- Honeypot: hidden from humans and assistive tech. --}}
            <div class="hidden" aria-hidden="true">
                <label for="book-website">Website</label>
                <input type="text" id="book-website" name="website" tabindex="-1" autocomplete="off" value="">
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="consent" value="true" required>
                <span>{{ __('site.book.consent') }}</span>
            </label>
            <p class="mt-1 text-xs text-red-600" data-error="consent" role="alert"></p>

            <p class="text-xs text-slate-500">{{ __('site.book.requiredNote') }}</p>

            <div id="booking-banner" class="hidden rounded-lg px-3 py-2 text-sm" role="alert"></div>

            <button type="submit" id="booking-submit" class="btn-primary w-full">{{ __('site.book.submit') }}</button>
        </form>

        <div id="booking-success" class="card mt-6 hidden p-5" role="status">
            <h2 class="text-lg font-semibold text-emerald-700">{{ __('site.book.successTitle') }}</h2>
            <p id="booking-success-body" class="mt-2 text-sm text-slate-700"></p>
            <button type="button" id="booking-again" class="btn-secondary mt-4">{{ __('site.book.successAgain') }}</button>
        </div>
    </div>
@endsection

@push('scripts')
@php
    use Illuminate\Support\Js;
@endphp
<script>
window.bookingErrors = {!! Js::from([
    'required' => __('site.book.errors.required'),
    'tooLong' => __('site.book.errors.tooLong'),
    'invalidDate' => __('site.book.errors.invalidDate'),
    'invalidTimeWindow' => __('site.book.errors.invalidTimeWindow'),
    'consent' => __('site.book.errors.consent'),
    'pastSlot' => __('site.book.errors.pastSlot'),
]) !!};
window.bookingSubmit = {!! Js::from(__('site.book.submit')) !!};
window.bookingSubmitting = {!! Js::from(__('site.book.submitting')) !!};
window.bookingErrorGeneric = {!! Js::from(__('site.book.errorGeneric')) !!};
window.bookingErrorRateLimited = {!! Js::from(__('site.book.errorRateLimited')) !!};
window.bookingErrorValidation = {!! Js::from(__('site.book.errorValidation')) !!};
window.bookingSuccessBody = {!! Js::from(__('site.book.successBody')) !!};
window.bookingSuccessBodyGeneric = {!! Js::from(__('site.book.successBodyGeneric')) !!};

(function () {
    var form = document.getElementById('booking-form');
    var banner = document.getElementById('booking-banner');
    var success = document.getElementById('booking-success');
    var successBody = document.getElementById('booking-success-body');
    var submit = document.getElementById('booking-submit');

    function showBanner(message, isError) {
        banner.textContent = message;
        banner.className = 'rounded-lg px-3 py-2 text-sm ' + (isError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700');
        banner.classList.remove('hidden');
    }

    function clearErrors() {
        document.querySelectorAll('[data-error]').forEach(function (el) { el.textContent = ''; });
    }

    function fieldError(field, code) {
        var el = document.querySelector('[data-error="' + field + '"]');
        if (!el) return;
        var known = { required: 1, tooLong: 1, invalidDate: 1, invalidTimeWindow: 1, consent: 1, pastSlot: 1, invalidPhone: 1 };
        el.textContent = known[code] ? window.bookingErrors[code] || '' : '';
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearErrors();
        banner.classList.add('hidden');

        var data = {
            name: form.name.value,
            phone: form.phone.value,
            preferredDate: form.preferredDate.value,
            preferredTimeWindow: form.preferredTimeWindow.value,
            serviceInterest: form.serviceInterest.value,
            message: form.message.value,
            language: document.documentElement.lang === 'am' ? 'am' : 'en',
            consent: form.consent.checked,
            website: form.website.value
        };

        submit.disabled = true;
        submit.textContent = window.bookingSubmitting;

        fetch('/api/public/requests', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (response) {
            return response.json().then(function (body) { return { status: response.status, body: body }; });
        }).then(function (result) {
            submit.disabled = false;
            submit.textContent = window.bookingSubmit;

            if (result.status === 200 && result.body.ok) {
                form.classList.add('hidden');
                success.classList.remove('hidden');
                // A request is filed, not confirmed — the front desk calls back.
                successBody.textContent = window.bookingSuccessBody || window.bookingSuccessBodyGeneric;
                return;
            }

            if (result.status === 429) { showBanner(window.bookingErrorRateLimited, true); return; }

            if (result.status === 422 && result.body.fields) {
                showBanner(window.bookingErrorValidation, true);
                Object.keys(result.body.fields).forEach(function (field) { fieldError(field, result.body.fields[field]); });
                return;
            }

            showBanner(window.bookingErrorGeneric, true);
        }).catch(function () {
            submit.disabled = false;
            submit.textContent = window.bookingSubmit;
            showBanner(window.bookingErrorGeneric, true);
        });
    });

    document.getElementById('booking-again').addEventListener('click', function () {
        form.reset();
        form.classList.remove('hidden');
        success.classList.add('hidden');
        clearErrors();
    });
})();
</script>
@endpush
