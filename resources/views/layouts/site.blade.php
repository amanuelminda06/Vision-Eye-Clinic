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

    {{-- The `js` class marks that JavaScript is running. The scroll-reveal
         styles only hide a card when it is set, so a blocked or failed
         script never leaves the page blank. --}}
    <script>document.documentElement.classList.add('js');</script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--background)] text-[var(--foreground)]">
    @php
        use App\Support\PublicSite\Content;
        use App\Support\PublicSite\Format;
        use App\Support\PublicSite\MapEmbed;
        use App\Support\PublicSite\PublicQueries;
        use App\Support\PublicSite\Seo;

        $siteContent = $siteContent ?? PublicQueries::getSiteContent();
        $locale = app()->getLocale();
        $clinicName = (string) \App\Support\Settings::get('clinic_name', 'Vision Eye Clinic');
        $primaryPhone = Content::pick($locale, $siteContent['contact_phone_primary']);
        $telHref = Format::telHref($primaryPhone);
        $announcementVisible = $announcementVisible ?? false;

        // The footer mini-map: the same Google Maps embed as the contact
        // page, built from the CMS position. NULL when the CMS has no
        // position — then no map is rendered.
        $footerZoom = (int) Content::pick($locale, $siteContent['map_zoom']);
        $footerMapUrl = MapEmbed::embedUrl(
            (float) Content::pick($locale, $siteContent['map_lat']),
            (float) Content::pick($locale, $siteContent['map_lng']),
            $footerZoom > 0 ? $footerZoom : MapEmbed::DEFAULT_ZOOM,
        );

        // `Request::path()` has NO leading slash ("about") while the nav
        // links DO ("/about") — so the "is this the current page?" test
        // never matched and only the home link ever looked selected.
        // Normalise once, here.
        $currentPath = '/'.ltrim(request()->path(), '/');

        // The navigation is rendered twice — the bar on wide screens and the
        // panel on narrow ones — so the list and the "is this the current
        // page?" test live here, once.
        $navItems = [
            '/' => 'navHome',
            '/about' => 'navAbout',
            '/services' => 'navServices',
            '/doctors' => 'navDoctors',
            '/contact' => 'navContact',
            '/faq' => 'navFaq',
        ];

        // Pages that open with a full-bleed hero photograph (the home page)
        // float the header OVER the picture: the header is fixed, transparent
        // and frosted at the top of the page, and turns solid once the page
        // is scrolled. Every other page gets the solid header and a body
        // that starts below it.
        $headerOverlay = $headerOverlay ?? false;
    @endphp

    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">{{ __('site.skipToContent') }}</a>

    {{-- The nav bar is a light-grey PILL: `rounded-full` on a fixed `h-16`
         turns both ends into semicircles. The header's vertical PADDING (not a
         margin on the pill) gives the pill room to breathe — a margin would
         collapse out of the header and the pill would overlap the page. The
         header's horizontal padding keeps the semicircles clear of the
         viewport edge. --}}
    <header id="site-header"
            class="group fixed inset-x-0 top-0 z-30 px-2 py-2.5 transition-colors duration-300 sm:px-4
                   {{ $headerOverlay ? 'bg-transparent data-[scrolled]:bg-white' : 'bg-white' }}"
            @if ($headerOverlay) data-scrollable @endif>
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-2 rounded-full border px-3 transition-colors duration-300 sm:gap-4 sm:px-6
                    {{ $headerOverlay
                        ? 'border-white/50 bg-white/70 backdrop-blur-lg group-data-[scrolled]:border-slate-200 group-data-[scrolled]:bg-slate-100 group-data-[scrolled]:backdrop-blur-none'
                        : 'border-slate-200 bg-slate-100' }}">
            <a href="/" class="flex min-w-0 items-center gap-3">
                @if ($logo = public_asset_url('/images/logo.png'))
                    {{-- The logo file is 271x183 (the transparent padding is
                         cropped off) — the intrinsic size keeps the header
                         from jumping while the image loads. --}}
                    <img src="{{ $logo }}" alt="{{ $clinicName }}" width="271" height="183"
                         class="h-9 w-auto shrink-0" decoding="async"
                         data-fallback="hide">
                @endif
                {{-- On a 360px phone there is no room left for the wordmark
                     once the language switcher, the booking button and the
                     menu button are placed, so it appears from `sm` up. The
                     logo image carries the brand; its `alt` is the name. --}}
                <span class="hidden flex-col leading-tight sm:flex">
                    <span class="truncate text-base font-semibold text-brand-800">{{ $clinicName }}</span>
                    <span class="truncate text-xs text-slate-500">{{ __('site.clinicNameAm') }}</span>
                </span>
            </a>

            {{-- The full nav needs ~430px; below the `lg` breakpoint it does
                 not fit next to the logo and the action buttons, so the
                 mobile menu is used there instead. --}}
            <nav class="ml-auto hidden items-center gap-1 lg:flex" aria-label="{{ __('site.menuLabel') }}">
                @foreach ($navItems as $href => $key)
                    @php $isCurrent = $currentPath === $href || ($href !== '/' && str_starts_with($currentPath, $href)); @endphp
                    {{-- The SELECTED link wears a filled chip. Every button
                         uses the same treatment, so whichever page you are on
                         paints its own button the same way. --}}
                    <a href="{{ $href }}"
                       class="rounded-lg px-3 py-1.5 text-sm transition {{ $isCurrent ? 'bg-brand-100 font-medium text-brand-800' : 'text-slate-600 hover:bg-brand-50 hover:text-brand-800' }}"
                       @if ($isCurrent) aria-current="page" @endif>
                        {{ __('site.'.$key) }}
                    </a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-1 sm:gap-2 lg:ml-0">
                @include('partials.locale-switcher')

                @if ($telHref)
                    <a href="{{ $telHref }}" class="btn-secondary hidden sm:inline-flex">{{ __('site.callNow') }}</a>
                @endif
                <a href="/book" class="btn-primary px-3 sm:px-5">{{ __('site.bookAppointment') }}</a>

                <button type="button" id="site-menu-toggle" class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-brand-800 hover:bg-brand-50 lg:hidden" aria-expanded="false" aria-controls="site-mobile-menu" aria-label="{{ __('site.menuLabel') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                </button>
            </div>
        </div>

        <div id="site-mobile-menu" class="hidden bg-white px-4 pb-3 lg:hidden">
            <nav class="flex flex-col gap-1" aria-label="{{ __('site.menuLabel') }}">
                @foreach ($navItems as $href => $key)
                    @php $isCurrent = $currentPath === $href || ($href !== '/' && str_starts_with($currentPath, $href)); @endphp
                    <a href="{{ $href }}"
                       class="rounded-lg px-3 py-2 text-sm transition {{ $isCurrent ? 'bg-brand-100 font-medium text-brand-800' : 'text-slate-700 hover:bg-brand-50' }}"
                       @if ($isCurrent) aria-current="page" @endif>
                        {{ __('site.'.$key) }}
                    </a>
                @endforeach
            </nav>
        </div>
    </header>

    <main id="main" class="{{ $headerOverlay ? '' : 'pt-[var(--site-header-h)]' }}">
        @if ($announcementVisible)
            {{-- On a hero page the header floats over the picture, so the
                 announcement sits just below it instead of behind it. --}}
            <div id="announcements" role="region" aria-label="{{ __('site.announcementLabel') }}"
                 class="relative z-20 bg-orange-500 px-4 py-2 text-center text-sm text-white {{ $headerOverlay ? 'mt-[var(--site-header-h)]' : '' }}">
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
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center px-1 py-1.5 text-xs text-brand-700 hover:underline">
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
                    <li><a href="/services" class="inline-block py-1 hover:text-brand-800">{{ __('site.navServices') }}</a></li>
                    <li><a href="/doctors" class="inline-block py-1 hover:text-brand-800">{{ __('site.navDoctors') }}</a></li>
                    <li><a href="/book" class="inline-block py-1 hover:text-brand-800">{{ __('site.bookAppointment') }}</a></li>
                    <li><a href="/contact" class="inline-block py-1 hover:text-brand-800">{{ __('site.navContact') }}</a></li>
                    <li><a href="/faq" class="inline-block py-1 hover:text-brand-800">{{ __('site.navFaq') }}</a></li>
                    <li><a href="/privacy" class="inline-block py-1 hover:text-brand-800">{{ __('site.footerPrivacy') }}</a></li>
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold text-slate-800">{{ __('site.footerContact') }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ Content::pick($locale, $siteContent['contact_address']) }}</p>
                @if ($telHref)
                    <a href="{{ $telHref }}" class="mt-1 inline-block text-sm text-brand-700 hover:underline">{{ $primaryPhone }}</a>
                @endif

                {{-- The mini-map: a Google Maps embed (no API key). It is
                     lazy — the footer is at the bottom of every page. --}}
                @if ($footerMapUrl !== null)
                    <iframe
                        src="{{ $footerMapUrl }}"
                        title="{{ __('site.contact.mapTitle') }}"
                        class="mt-3 h-28 w-full rounded-xl border border-slate-200 bg-slate-50"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen></iframe>
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

            // A page that opens with a hero picture floats the header over it:
            // transparent and frosted at the top, solid once the page scrolls.
            var header = document.getElementById('site-header');
            if (header && header.hasAttribute('data-scrollable')) {
                var onScroll = function () {
                    if (window.scrollY > 8) {
                        header.setAttribute('data-scrolled', '');
                    } else {
                        header.removeAttribute('data-scrolled');
                    }
                };
                onScroll();
                window.addEventListener('scroll', onScroll, { passive: true });
            }
        })();
    </script>
    @stack('scripts')
</body>
</html>
