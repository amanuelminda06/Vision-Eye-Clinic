@extends('layouts.site')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-12">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('site.contact.title') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('site.contact.intro') }}</p>

        @php
            use App\Support\PublicSite\Content;
            use App\Support\PublicSite\Format;
            use App\Support\PublicSite\MapEmbed;

            $locale = app()->getLocale();
            $address = Content::pick($locale, $content['contact_address']);
            $phone = Content::pick($locale, $content['contact_phone_primary']);
            $backup = Content::pick($locale, $content['contact_phone_backup']);
            $mapUrl = Content::pick($locale, $content['contact_map_url']);
            $telHref = Format::telHref($phone);

            // The map position comes from the CMS; the Google Maps link is
            // the fallback when the map cannot be loaded.
            $mapLat = (float) Content::pick($locale, $content['map_lat']);
            $mapLng = (float) Content::pick($locale, $content['map_lng']);
            $mapZoom = (int) Content::pick($locale, $content['map_zoom']);

            // The embed URL is NULL when the CMS has no position — then the
            // map block below is not rendered at all.
            $mapEmbedUrl = MapEmbed::embedUrl($mapLat, $mapLng, $mapZoom > 0 ? $mapZoom : MapEmbed::DEFAULT_ZOOM);

            $googleMapsUrl = $mapUrl !== ''
                ? $mapUrl
                : 'https://www.google.com/maps/search/?api=1&query='.urlencode($address !== '' ? $address : $mapLat.','.$mapLng);
        @endphp

        <div class="mt-8 grid gap-5 sm:grid-cols-3">
            <div class="card card-hover reveal p-5">
                <h2 class="text-sm font-semibold text-slate-900">{{ __('site.contact.address') }}</h2>
                @if ($address !== '')
                    <p class="mt-2 text-sm text-slate-600">{{ $address }}</p>
                    @if (preg_match('#/maps\.app\.goo\.gl/i#', $mapUrl) === 1)
                        <a href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-sm text-brand-700 hover:underline">{{ __('site.contact.directions') }}</a>
                    @endif
                @else
                    <p class="mt-2 text-sm text-slate-500">{{ __('site.contact.pending') }}</p>
                @endif
            </div>

            <div class="card card-hover reveal p-5">
                <h2 class="text-sm font-semibold text-slate-900">{{ __('site.contact.phone') }}</h2>
                @if ($telHref)
                    <a href="{{ $telHref }}" class="mt-2 block text-lg font-semibold text-brand-800">{{ $phone }}</a>
                    @if ($backup !== '')
                        <p class="mt-1 text-xs text-slate-500">{{ __t('site.phoneBackupLabel', ['number' => $backup]) }}</p>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($whatsapp = Format::whatsappHref($phone))
                            <a href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer" class="btn-secondary px-2 py-1 text-xs">{{ __('site.contact.whatsapp') }}</a>
                        @endif
                        @if ($telegram = Format::telegramHref($phone))
                            <a href="{{ $telegram }}" target="_blank" rel="noopener noreferrer" class="btn-secondary px-2 py-1 text-xs">{{ __('site.contact.telegram') }}</a>
                        @endif
                    </div>
                @else
                    <p class="mt-2 text-sm text-slate-500">{{ __('site.phonePending') }}</p>
                @endif
            </div>

            <div class="card card-hover reveal p-5">
                <h2 class="text-sm font-semibold text-slate-900">{{ __('site.contact.hours') }}</h2>
                <ul class="mt-2 space-y-1 text-sm text-slate-600">
                    @foreach ($hours as $line)
                        <li class="flex justify-between gap-2">
                            <span>{{ $line['day'] }}</span>
                            <span class="tabular-nums">{{ $line['value'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- The map: a Google Maps embed (no API key, no third-party script). --}}
        <section class="mt-10">
            @if ($mapEmbedUrl !== null)
                <h2 class="text-lg font-semibold text-slate-900">{{ __('site.contact.mapTitle') }}</h2>

                <iframe
                    src="{{ $mapEmbedUrl }}"
                    title="{{ __('site.contact.mapTitle') }}"
                    class="mt-3 h-64 w-full rounded-2xl border border-slate-200 bg-slate-50"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    allowfullscreen></iframe>
            @endif

            {{-- The address and the link are ALWAYS there: they are the
                 fallback when the map cannot be loaded. --}}
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-600">
                    @if ($address !== '')
                        {{ $address }}
                    @else
                        {{ __('site.contact.pending') }}
                    @endif
                </p>

                <a href="{{ $googleMapsUrl }}" target="_blank" rel="noopener noreferrer" class="btn-secondary text-sm">
                    {{ __('site.openInMaps') }}
                </a>
            </div>
        </section>

        {{-- Feedback form --}}
        <section class="card mt-10 p-5">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('site.feedback.title') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('site.feedback.intro') }}</p>

            <form id="feedback-form" class="mt-4 space-y-4">
                <div>
                    <label class="label" for="feedback-name">{{ __('site.feedback.name') }} <span class="text-red-500">*</span></label>
                    <input class="input-field" id="feedback-name" name="name" maxlength="120" required placeholder="{{ __('site.feedback.namePlaceholder') }}">
                    <p class="mt-1 text-xs text-red-600" data-error="name" role="alert"></p>
                </div>

                <div>
                    <label class="label" for="feedback-email">{{ __('site.feedback.email') }} <span class="text-xs text-slate-500">({{ __('site.feedback.optional') }})</span></label>
                    <input class="input-field" id="feedback-email" name="email" type="email" maxlength="254" placeholder="{{ __('site.feedback.emailPlaceholder') }}">
                    <p class="mt-1 text-xs text-slate-500">{{ __('site.feedback.emailHint') }}</p>
                    <p class="mt-1 text-xs text-red-600" data-error="email" role="alert"></p>
                </div>

                <div>
                    <label class="label" for="feedback-rating">{{ __('site.feedback.rating') }}</label>
                    <select class="input-field" id="feedback-rating" name="rating">
                        <option value="">{{ __('site.feedback.ratingNone') }}</option>
                        <option value="1">{{ __('site.feedback.ratingPoor') }}</option>
                        <option value="2">{{ __('site.feedback.ratingFair') }}</option>
                        <option value="3">{{ __('site.feedback.ratingGood') }}</option>
                        <option value="4">{{ __('site.feedback.ratingVeryGood') }}</option>
                        <option value="5">{{ __('site.feedback.ratingExcellent') }}</option>
                    </select>
                    <p class="mt-1 text-xs text-red-600" data-error="rating" role="alert"></p>
                </div>

                <div>
                    <label class="label" for="feedback-message">{{ __('site.feedback.message') }} <span class="text-red-500">*</span></label>
                    <textarea class="input-field" id="feedback-message" name="message" rows="4" maxlength="1000" required placeholder="{{ __('site.feedback.messagePlaceholder') }}"></textarea>
                    <p class="mt-1 text-xs text-slate-500">{{ __t('site.feedback.messageHint', ['max' => 1000]) }}</p>
                    <p class="mt-1 text-xs text-red-600" data-error="message" role="alert"></p>
                </div>

                <div class="hidden" aria-hidden="true">
                    <label for="feedback-website">Website</label>
                    <input type="text" id="feedback-website" name="website" tabindex="-1" autocomplete="off" value="">
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="consent" value="true" required class="mt-0.5 h-5 w-5 shrink-0 accent-brand-700">
                    <span>{{ __('site.feedback.consent') }}</span>
                </label>
                <p class="mt-1 text-xs text-red-600" data-error="consent" role="alert"></p>

                <p class="text-xs text-slate-500">{{ __('site.feedback.requiredNote') }}</p>

                <div id="feedback-banner" class="hidden rounded-lg px-3 py-2 text-sm" role="alert"></div>

                <button type="submit" id="feedback-submit" class="btn-primary w-full">{{ __('site.feedback.submit') }}</button>
            </form>

            <div id="feedback-success" class="mt-4 hidden rounded-lg bg-emerald-50 px-4 py-3" role="status">
                <h3 class="text-sm font-semibold text-emerald-800">{{ __('site.feedback.successTitle') }}</h3>
                <p class="mt-1 text-sm text-emerald-700">{{ __('site.feedback.successBody') }}</p>
                <button type="button" id="feedback-again" class="btn-secondary mt-3">{{ __('site.feedback.successAgain') }}</button>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
@php
    use Illuminate\Support\Js;
@endphp
<script>
window.feedbackErrors = {!! Js::from([
    'required' => __('site.feedback.errors.required'),
    'tooLong' => __('site.feedback.errors.tooLong'),
    'invalidEmail' => __('site.feedback.errors.invalidEmail'),
    'invalidRating' => __('site.feedback.errors.invalidRating'),
    'consent' => __('site.feedback.errors.consent'),
]) !!};
window.feedbackSubmit = {!! Js::from(__('site.feedback.submit')) !!};
window.feedbackSubmitting = {!! Js::from(__('site.feedback.submitting')) !!};
window.feedbackErrorGeneric = {!! Js::from(__('site.feedback.errorGeneric')) !!};
window.feedbackErrorRateLimited = {!! Js::from(__('site.feedback.errorRateLimited')) !!};
window.feedbackErrorValidation = {!! Js::from(__('site.feedback.errorValidation')) !!};

(function () {
    var form = document.getElementById('feedback-form');
    var banner = document.getElementById('feedback-banner');
    var success = document.getElementById('feedback-success');
    var submit = document.getElementById('feedback-submit');

    function showBanner(message, isError) {
        banner.textContent = message;
        banner.className = 'rounded-lg px-3 py-2 text-sm ' + (isError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700');
        banner.classList.remove('hidden');
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        document.querySelectorAll('[data-error]').forEach(function (el) { el.textContent = ''; });
        banner.classList.add('hidden');

        var data = {
            name: form.name.value,
            email: form.email.value,
            rating: form.rating.value,
            message: form.message.value,
            language: document.documentElement.lang === 'am' ? 'am' : 'en',
            consent: form.consent.checked,
            website: form.website.value
        };

        submit.disabled = true;
        submit.textContent = window.feedbackSubmitting;

        fetch('/api/public/feedback', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (response) {
            return response.json().then(function (body) { return { status: response.status, body: body }; });
        }).then(function (result) {
            submit.disabled = false;
            submit.textContent = window.feedbackSubmit;

            if (result.status === 200 && result.body.ok) {
                form.classList.add('hidden');
                success.classList.remove('hidden');
                return;
            }

            if (result.status === 429) { showBanner(window.feedbackErrorRateLimited, true); return; }

            if (result.status === 422 && result.body.fields) {
                showBanner(window.feedbackErrorValidation, true);
                Object.keys(result.body.fields).forEach(function (field) {
                    var el = document.querySelector('[data-error="' + field + '"]');
                    if (el) el.textContent = window.feedbackErrors[result.body.fields[field]] || '';
                });
                return;
            }

            showBanner(window.feedbackErrorGeneric, true);
        }).catch(function () {
            submit.disabled = false;
            submit.textContent = window.feedbackSubmit;
            showBanner(window.feedbackErrorGeneric, true);
        });
    });

    document.getElementById('feedback-again').addEventListener('click', function () {
        form.reset();
        form.classList.remove('hidden');
        success.classList.add('hidden');
    });
})();
</script>
@endpush
