<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('app.name'))</title>

    {{-- Icons: a real favicon.ico plus the modern PNG sizes. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/favicon-192.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--background)] text-[var(--foreground)]">
    @php
        $currentUser = auth()->user();
        $currentRole = $currentUser->role ?? \App\Support\Roles::STAFF;
        $messageCount = in_array($currentRole, [\App\Support\Roles::OWNER, \App\Support\Roles::RECEPTION], true)
            ? \App\Support\MessageBadge::count()
            : 0;
        $requestCount = in_array($currentRole, [\App\Support\Roles::OWNER, \App\Support\Roles::RECEPTION], true)
            ? \App\Support\RequestBadge::count()
            : 0;
        $navModules = \App\Support\Nav::visibleFor($currentRole);
        $roleLabelKey = 'common.role'.ucfirst(strtolower($currentRole));
    @endphp

    {{-- Header (h-14, sticky) --}}
    <header class="sticky top-0 z-30 h-14 border-b border-brand-200 bg-white">
        <div class="flex h-14 items-center gap-3 px-3 md:px-4">
            <button type="button" id="nav-toggle" class="md:hidden" aria-label="{{ __('nav.menu') }}" aria-controls="nav-drawer" aria-expanded="false">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            </button>

            <a href="{{ route('dashboard') }}" class="text-sm font-semibold text-brand-800 md:text-base">{{ __('app.shortName') }}</a>

            {{-- Global search: OWNER + RECEPTION, hidden below sm (endpoint lands in slice 8) --}}
            @if (in_array($currentRole, [\App\Support\Roles::OWNER, \App\Support\Roles::RECEPTION], true))
                <form class="hidden flex-1 max-w-md sm:block" action="/api/search" method="get" role="search">
                    <input type="search" name="q" maxlength="100" autocomplete="off"
                           class="input-field !py-1.5 text-sm"
                           placeholder="{{ __('app.searchPlaceholder') }}"
                           aria-label="{{ __('app.searchPlaceholder') }}">
                </form>
            @endif

            <div class="ml-auto flex items-center gap-2">
                @include('partials.locale-switcher')

                {{-- User menu --}}
                <details class="relative" data-dropdown>
                    <summary class="flex cursor-pointer list-none items-center gap-2 rounded-full px-2 py-1 hover:bg-brand-50">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-700 text-xs font-semibold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($currentUser->name ?? '?', 0, 1)) }}</span>
                        <span class="hidden text-sm sm:inline">
                            <span class="font-medium">{{ $currentUser->name }}</span>
                            <span class="text-slate-500">({{ __($roleLabelKey) }})</span>
                        </span>
                    </summary>
                    <div class="absolute right-0 z-40 mt-2 w-56 rounded-xl border border-brand-200 bg-white p-3 shadow-lg">
                        <p class="truncate text-xs text-slate-500">{{ $currentUser->email }}</p>
                        <a href="{{ route('password.change') }}" class="mt-2 block rounded-lg px-2 py-1.5 text-sm hover:bg-brand-50">{{ __('common.changePassword') }}</a>
                        <form method="POST" action="{{ route('logout') }}" class="mt-1">
                            @csrf
                            <button type="submit" class="w-full rounded-lg px-2 py-1.5 text-left text-sm text-red-600 hover:bg-red-50">{{ __('common.signOut') }}</button>
                        </form>
                    </div>
                </details>
            </div>
        </div>
    </header>

    <div class="flex">
        {{-- Sidebar (w-56, sticky under header) --}}
        <aside class="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-56 shrink-0 overflow-y-auto border-r border-brand-200 bg-white p-3 md:block">
            @include('partials.nav', ['modules' => $navModules, 'messageCount' => $messageCount, 'requestCount' => $requestCount])
        </aside>

        <main class="min-w-0 flex-1 p-4 md:p-6">
            @if (session('status'))
                <div role="status" class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700">
                    {{ session('status') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    {{-- Mobile drawer --}}
    <div id="nav-drawer" class="fixed inset-0 z-40 hidden md:hidden" data-open="false">
        <div class="absolute inset-0 bg-slate-900/40" data-drawer-close aria-label="{{ __('common.close') }}"></div>
        <nav class="absolute left-0 top-0 h-full w-64 overflow-y-auto bg-white p-3 shadow-xl" aria-label="{{ __('nav.menu') }}">
            @include('partials.nav', ['modules' => $navModules, 'messageCount' => $messageCount, 'requestCount' => $requestCount])
            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button type="submit" class="btn-danger w-full">{{ __('common.signOut') }}</button>
            </form>
        </nav>
    </div>

    <script>
        (function () {
            var toggle = document.getElementById('nav-toggle');
            var drawer = document.getElementById('nav-drawer');
            if (toggle && drawer) {
                toggle.addEventListener('click', function () {
                    var open = drawer.getAttribute('data-open') === 'true';
                    drawer.classList.toggle('hidden', open);
                    drawer.setAttribute('data-open', open ? 'false' : 'true');
                    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
                });
                drawer.querySelectorAll('[data-drawer-close]').forEach(function (el) {
                    el.addEventListener('click', function () {
                        drawer.classList.add('hidden');
                        drawer.setAttribute('data-open', 'false');
                        toggle.setAttribute('aria-expanded', 'false');
                    });
                });
            }
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                if (drawer && drawer.getAttribute('data-open') === 'true') {
                    drawer.classList.add('hidden');
                    drawer.setAttribute('data-open', 'false');
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');
                }
                document.querySelectorAll('details[data-dropdown][open]').forEach(function (d) {
                    d.removeAttribute('open');
                });
            });
        })();
    </script>
    @stack('scripts')
</body>
</html>
