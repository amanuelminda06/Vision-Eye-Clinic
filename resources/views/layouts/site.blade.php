<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $seo = $seo ?? [];
        $path = $seo['canonical'] ?? '/';
        $base = \App\Support\PublicSite\Seo::siteBaseUrl();
    @endphp

    <title>{{ $seo['title'] ?? __('app.name') }}</title>
    <meta name="description" content="{{ $seo['description'] ?? '' }}">

    <link rel="canonical" href="{{ $base }}{{ $path }}">
    @foreach (($seo['languages'] ?? []) as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $base }}{{ $href }}">
    @endforeach

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Vision Eye Clinic">
    <meta property="og:title" content="{{ $seo['openGraph']['title'] ?? '' }}">
    <meta property="og:description" content="{{ $seo['openGraph']['description'] ?? '' }}">
    <meta property="og:url" content="{{ $seo['openGraph']['url'] ?? $base }}">
    <meta property="og:locale" content="en_ET">
    <meta property="og:locale:alternate" content="am_ET">
    {{-- The hero image is 1200x630 — the Open Graph size. --}}
    <meta property="og:image" content="{{ public_asset_url('/images/hero.jpg') ?? asset('images/hero.jpg') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['twitter']['title'] ?? '' }}">
    <meta name="twitter:description" content="{{ $seo['twitter']['description'] ?? '' }}">

    {{-- Icons: a real favicon.ico plus the modern PNG sizes. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/favicon-192.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--background)] text-[var(--foreground)]">
    @php
        use App\Support\PublicSite\Content;
        use App\Support\PublicSite\Format;
        use App\Support\PublicSite\PublicQueries;
        use App\Support\PublicSite\Seo;

        $siteContent = $siteContent ?? PublicQueries::getSiteContent();
        $locale = app()->getLocale();
        $clinicName = (string) \App\Support\Settings::get('clinic_name', 'Vision Eye Clinic');
        $primaryPhone = Content::pick($locale, $siteContent['contact_phone_primary']);
        $telHref = Format::telHref($primaryPhone);
        $announcementVisible = $announcementVisible ?? false;
        $currentPath = request()->path();
    @endphp

    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">{{ __('site.skipToContent') }}</a>

    <header class="sticky top-0 z-30 border-b border-brand-200 bg-white">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4">
            <a href="/" class="flex items-center gap-3">
                @if ($logo = public_asset_url('/images/logo.png'))
                    {{-- The logo file is 271x183 (the transparent padding is
                         cropped off) — the intrinsic size keeps the header
                         from jumping while the image loads. --}}
                    <img src="{{ $logo }}" alt="{{ $clinicName }}" width="271" height="183"
                         class="h-9 w-auto" decoding="async"
                         data-fallback="hide">
                @endif
                <span class="flex flex-col leading-tight">
                    <span class="text-base font-semibold text-brand-800">{{ $clinicName }}</span>
                    <span class="text-xs text-slate-500">{{ __('site.clinicNameAm') }}</span>
                </span>
            </a>

            <nav class="ml-auto hidden items-center gap-1 md:flex" aria-label="{{ __('site.menuLabel') }}">
                @foreach ([
                    '/' => 'navHome',
                    '/about' => 'navAbout',
                    '/services' => 'navServices',
                    '/doctors' => 'navDoctors',
                    '/contact' => 'navContact',
                    '/faq' => 'navFaq',
                ] as $href => $key)
                    <a href="{{ $href }}"
                       class="rounded-lg px-3 py-1.5 text-sm {{ $currentPath === $href || ($href !== '/' && str_starts_with($currentPath, $href)) ? 'border-b-2 border-orange-500 font-medium text-brand-800' : 'text-slate-600 hover:text-brand-800' }}"
                       @if ($currentPath === $href || ($href !== '/' && str_starts_with($currentPath, $href))) aria-current="page" @endif>
                        {{ __('site.'.$key) }}
                    </a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-2 md:ml-0">
                @include('partials.locale-switcher')

                @if ($telHref)
                    <a href="{{ $telHref }}" class="btn-secondary hidden sm:inline-flex">{{ __('site.callNow') }}</a>
                @endif
                <a href="/book" class="btn-primary">{{ __('site.bookAppointment') }}</a>

                <button type="button" id="site-menu-toggle" class="md:hidden" aria-expanded="false" aria-controls="site-mobile-menu" aria-label="{{ __('site.menuLabel') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                </button>
            </div>
        </div>

        <div id="site-mobile-menu" class="hidden border-t border-brand-100 bg-white px-4 py-3 md:hidden">
            <nav class="flex flex-col gap-1" aria-label="{{ __('site.menuLabel') }}">
                @foreach ([
                    '/' => 'navHome',
                    '/about' => 'navAbout',
                    '/services' => 'navServices',
                    '/doctors' => 'navDoctors',
                    '/contact' => 'navContact',
                    '/faq' => 'navFaq',
                ] as $href => $key)
                    <a href="{{ $href }}" class="rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-brand-50">{{ __('site.'.$key) }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <main id="main">
        @if ($announcementVisible)
            <div id="announcements" role="region" aria-label="{{ __('site.announcementLabel') }}" class="bg-orange-500 px-4 py-2 text-center text-sm text-white">
                {{ Content::pick($locale, $siteContent['announcement_text']) }}
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="mt-16 border-t border-brand-200 bg-white">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 sm:grid-cols-3">
            <div>
                <p class="text-sm font-semibold text-brand-800">{{ $clinicName }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ __('site.footerAbout') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ Content::pick($locale, $siteContent['footer_note']) }}</p>

                <div class="mt-3 flex items-center gap-3">
                    @foreach ([
                        'social_tiktok' => 'TikTok',
                        'social_instagram' => 'Instagram',
                        'social_telegram' => 'Telegram',
                    ] as $key => $label)
                        @php $url = Content::pick($locale, $siteContent[$key]); @endphp
                        @if ($url !== '')
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-700 hover:underline">
                                <span class="sr-only">{{ $label }}</span>
                                <span aria-hidden="true">{{ $label }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-800">{{ __('site.footerQuickLinks') }}</p>
                <ul class="mt-2 space-y-1 text-sm text-slate-600">
                    <li><a href="/services" class="hover:text-brand-800">{{ __('site.navServices') }}</a></li>
                    <li><a href="/doctors" class="hover:text-brand-800">{{ __('site.navDoctors') }}</a></li>
                    <li><a href="/book" class="hover:text-brand-800">{{ __('site.bookAppointment') }}</a></li>
                    <li><a href="/contact" class="hover:text-brand-800">{{ __('site.navContact') }}</a></li>
                    <li><a href="/faq" class="hover:text-brand-800">{{ __('site.navFaq') }}</a></li>
                    <li><a href="/privacy" class="hover:text-brand-800">{{ __('site.footerPrivacy') }}</a></li>
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-800">{{ __('site.footerContact') }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ Content::pick($locale, $siteContent['contact_address']) }}</p>
                @if ($telHref)
                    <a href="{{ $telHref }}" class="mt-1 inline-block text-sm text-brand-700 hover:underline">{{ $primaryPhone }}</a>
                @endif
            </div>
        </div>

        <div class="border-t border-brand-100 bg-brand-50/50">
            <p class="mx-auto max-w-6xl px-4 py-3 text-xs text-slate-500">
                {{ __t('site.footerRights', ['year' => now()->year, 'clinic' => $clinicName]) }}
            </p>
        </div>
    </footer>

    <script>
        (function () {
            var toggle = document.getElementById('site-menu-toggle');
            var menu = document.getElementById('site-mobile-menu');
            if (toggle && menu) {
                toggle.addEventListener('click', function () {
                    var open = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
                    menu.classList.toggle('hidden', open);
                });
            }
        })();
    </script>
    @stack('scripts')
</body>
</html>
