@extends('layouts.site', ['headerOverlay' => true])

@section('content')
    {{-- HERO — the photograph is a full-bleed BACKGROUND that runs up behind
         the fixed, frosted header, so the picture sits behind the navigation.
         The headline and the buttons sit ON the picture; the "why patients
         trust us" dot points sit BELOW it, in their own band. --}}
    <section class="relative isolate overflow-hidden bg-slate-900">
        @if ($hero = public_asset_url('/images/hero.jpg'))
            {{-- The hero is the LCP element: it is loaded eagerly and
                 with a high priority. A broken file falls back to a
                 neutral block of the same shape — never a broken box. --}}
            <img src="{{ $hero }}" alt="{{ __('site.home.heroImageAlt') }}"
                 width="1119" height="630"
                 loading="eager" fetchpriority="high" decoding="async"
                 class="absolute inset-0 -z-10 h-full w-full object-cover"
                 data-fallback="placeholder">
        @endif
        {{-- Two scrims. The vertical one only shades the very top and bottom
             edges, so the photograph still READS behind the navigation. The
             horizontal one carries the headline: strong on the left where the
             words sit, almost clear on the right where the picture is free. --}}
        <div class="absolute inset-0 -z-10 bg-gradient-to-b from-slate-950/25 via-slate-950/5 to-slate-950/30"></div>
        <div class="absolute inset-0 -z-10 bg-gradient-to-r from-slate-950/90 via-slate-950/78 to-slate-950/55 sm:via-slate-950/55 sm:to-slate-950/5"></div>

        <div class="mx-auto max-w-6xl px-4 pb-14 pt-[var(--site-header-h)] sm:pb-20">
            <div class="max-w-2xl pt-10 sm:pt-16">
                <span class="inline-flex rounded-full bg-brand-100 px-3 py-1 text-xs font-medium text-brand-800">
                    {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['hero_promise']) }}
                </span>
                <h1 class="mt-4 text-3xl font-semibold text-white sm:text-4xl lg:text-5xl">
                    {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['hero_title']) }}
                </h1>
                <p class="mt-4 text-base text-slate-100 sm:text-lg">
                    {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['hero_subtitle']) }}
                </p>
                <p class="mt-3 max-w-xl text-sm text-slate-200/90">
                    {{ \App\Support\PublicSite\Content::pick(app()->getLocale(), $content['home_intro']) }}
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="/book" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-brand-800 shadow-lg shadow-black/25 transition hover:-translate-y-px hover:bg-brand-50">
                        {{ __('site.bookAppointment') }}
                    </a>
                    @php $tel = \App\Support\PublicSite\Format::telHref(\App\Support\PublicSite\Content::pick(app()->getLocale(), $content['contact_phone_primary'])); @endphp
                    @if ($tel)
                        <a href="{{ $tel }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/60 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/20">
                            {{ __('site.callNow') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- WHY PATIENTS TRUST US — the dot points, BELOW the hero picture. --}}
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-8">
            <h2 class="text-sm font-semibold text-slate-800">{{ __('site.home.whyTitle') }}</h2>
            <ul class="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @for ($i = 1; $i <= 3; $i++)
                    <li class="reveal flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-orange-50 text-orange-600">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <span>
                            <span class="block text-sm font-medium text-slate-800">{{ __('site.home.why'.$i.'Title') }}</span>
                            <span class="mt-0.5 block text-xs text-slate-600">{{ __('site.home.why'.$i.'Text') }}</span>
                        </span>
                    </li>
                @endfor
            </ul>
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
                    <article class="card card-hover reveal overflow-hidden p-0">
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
                    <article class="card card-hover reveal p-4">
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
                    <figure class="card card-hover reveal p-5">
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
