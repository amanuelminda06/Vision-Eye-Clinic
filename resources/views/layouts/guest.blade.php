<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center px-4">
    <main class="w-full max-w-sm">
        @yield('content')

        <div class="mt-4 flex items-center justify-center gap-3">
            @include('partials.locale-switcher')
        </div>
    </main>
</body>
</html>
