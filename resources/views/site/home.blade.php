@extends('layouts.site')

@section('content')
    <section class="bg-brand-50/60">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 lg:grid-cols-2">
            <div>
                <span class="inline-flex rounded-full bg-brand-100 px-3 py-1 text-xs font-medium text-brand-800">
                    {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['hero_promise']) }}
                </span>
                <h1 class="mt-4 text-3xl font-semibold text-slate-900 sm:text-4xl">
                    {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['hero_title']) }}
                </h1>
                <p class="mt-3 text-base text-slate-600">
                    {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['hero_subtitle']) }}
                </p>
                <p class="mt-3 text-sm text-slate-600">
                    {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['home_intro']) }}
                </p>

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="/book" class="btn-primary">{{ __('site.bookAppointment') }}</a>
                    @php $tel = \App\Support\PublicSite\Format::telHref(\App\Support\PublicSite\Content::pick(app()->getLocale(), $content['contact_phone_primary'])); @endphp
                    @if ($tel)
                        <a href="{{ $tel }}" class="btn-secondary">{{ __('site.callNow') }}</a>
                    @endif
                </div>

                @if ($hero = public_asset_url('/images/hero.jpg'))
                    {{-- The hero is the LCP element: it is loaded eagerly and
                         with a high priority. A broken file falls back to a
                         neutral block of the same shape — never a broken box. --}}
                    <img src="{{ $hero }}" alt="{{ __('site.home.heroImageAlt') }}"
                         width="1200" height="630"
                         loading="eager" fetchpriority="high" decoding="async"
                         class="mt-6 w-full rounded-2xl border border-brand-100 object-cover"
                         data-fallback="placeholder">
                @endif
            </div>

            <div class="card p-5">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('site.home.whyTitle') }}</h2>
                <ul class="mt-3 space-y-3">
                    @for ($i = 1; $i <= 3; $i++)
                        <li class="flex gap-2">
                            <span class="mt-0.5 text-emerald-600" aria-hidden="true">✓</span>
                            <span>
                                <span class="block text-sm font-medium text-slate-800">{{ __('site.home.why'.$i.'Title') }}</span>
                                <span class="block text-xs text-slate-600">{{ __('site.home.why'.$i.'Text') }}</span>
                            </span>
                        </li>
                    @endfor
                </ul>
            </div>
        </div>
    </section>

    {{-- Services --}}
    <section class="mx-auto max-w-6xl px-4 py-12">
        <h2 class="text-2xl font-semibold text-slate-900">{{ __('site.home.servicesTitle') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('site.home.servicesIntro') }}</p>

        @if ($services === [])
            <div class="mt-6 rounded-xl border border-dashed border-brand-200 p-6 text-sm text-slate-600">{{ __('site.services.empty') }}</div>
        @else
            <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($services as $service)
                    @php
                        $image = \App\Support\PublicSite\Format::publicServiceImageUrl($service['id'], $service['imageUrl']);
                        $serviceName = app()->getLocale() === 'am'
                            ? ($service['nameAm'] ?: ($service['nameEn'] ?: $service['name']))
                            : ($service['nameEn'] ?: $service['name']);
                    @endphp
                    <article class="card overflow-hidden p-0">
                        @if ($image)
                            {{-- The service images are 1200x800 — the intrinsic
                                 size reserves the space, so the card does not
                                 jump while the picture loads. --}}
                            <img src="{{ $image }}" alt="{{ $serviceName }}" width="1200" height="800"
                                 loading="lazy" decoding="async"
                                 class="h-40 w-full object-cover"
                                 data-fallback="placeholder"
                                 data-fallback-text="{{ mb_strtoupper(mb_substr($serviceName, 0, 1)) }}">
                        @endif
                        <div class="space-y-2 p-4">
                            <h3 class="text-base font-semibold text-slate-900">
                                {{ $serviceName }}
                            </h3>
                            @php $description = app()->getLocale() === 'am' ? ($service['descriptionAm'] ?: $service['descriptionEn']) : $service['descriptionEn']; @endphp
                            @if ($description)
                                <p class="text-sm text-slate-600">{{ $description }}</p>
                            @endif
                            <a href="/book" class="text-sm text-brand-700 hover:underline">{{ __('site.services.bookService') }}</a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <a href="/services" class="mt-4 inline-block text-sm text-brand-700 hover:underline">{{ __('site.home.viewAllServices') }} →</a>
    </section>

    {{-- Doctors --}}
    <section class="bg-brand-50/40">
        <div class="mx-auto max-w-6xl px-4 py-12">
            <h2 class="text-2xl font-semibold text-slate-900">{{ __('site.home.doctorsTitle') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('site.home.doctorsIntro') }}</p>

            @if (! $hasDoctors)
                <div class="mt-6 rounded-xl border border-dashed border-brand-200 p-6 text-sm text-slate-600">{{ __('site.doctors.empty') }}</div>
            @else
                <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($doctors as $doctor)
                        @include('site.partials.doctor-card', ['doctor' => $doctor])
                    @endforeach
                </div>
            @endif

            <a href="/doctors" class="mt-4 inline-block text-sm text-brand-700 hover:underline">{{ __('site.home.viewAllDoctors') }} →</a>
        </div>
    </section>

    {{-- Announcements --}}
    @if ($announcements !== [])
        <section class="mx-auto max-w-6xl px-4 py-12">
            <h2 class="text-2xl font-semibold text-slate-900">{{ __('site.home.announcementsTitle') }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach ($announcements as $item)
                    <article class="card p-4">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $item['title'] }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ $item['message'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Testimonials --}}
    @if ($testimonials !== [])
        <section class="mx-auto max-w-6xl px-4 py-12">
            <h2 class="text-2xl font-semibold text-slate-900">{{ __('site.home.testimonialsTitle') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('site.home.testimonialsIntro') }}</p>

            <div class="mt-6 grid gap-5 sm:grid-cols-3">
                @foreach ($testimonials as $testimonial)
                    <figure class="card p-5">
                        @if (! empty($testimonial['rating']))
                            <div role="img" aria-label="{{ __t('site.home.ratingAria', ['rating' => $testimonial['rating'], 'max' => 5]) }}" class="text-amber-500">
                                @for ($star = 1; $star <= 5; $star++)
                                    <span aria-hidden="true">{{ $star <= (int) $testimonial['rating'] ? '★' : '☆' }}</span>
                                @endfor
                            </div>
                        @endif
                        <blockquote class="mt-2 text-sm text-slate-700">{{ $testimonial['text'] }}</blockquote>
                        <figcaption class="mt-3 text-xs font-medium text-slate-600">{{ $testimonial['name'] }}</figcaption>
                    </figure>
                @endforeach
            </div>
        </section>
    @endif

    {{-- CTA --}}
    <section class="bg-orange-500">
        <div class="mx-auto max-w-6xl px-4 py-12 text-center text-white">
            <h2 class="text-2xl font-semibold">{{ __('site.home.ctaTitle') }}</h2>
            <p class="mt-2 text-sm">{{ __('site.home.ctaText') }}</p>
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <a href="/book" class="btn-secondary">{{ __('site.bookAppointment') }}</a>
                @php $tel = \App\Support\PublicSite\Format::telHref(\App\Support\PublicSite\Content::pick(app()->getLocale(), $content['contact_phone_primary'])); @endphp
                @if ($tel)
                    <a href="{{ $tel }}" class="btn-secondary">{{ __('site.callNow') }}</a>
                @endif
            </div>
        </div>
    </section>
@endsection
