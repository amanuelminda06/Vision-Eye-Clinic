@php
    use App\Support\Nav;
@endphp
@foreach ($modules as $module)
    <div class="mb-3">
        @if ($module['labelKey'])
            <details open class="group mb-1" title="{{ __('nav.expand') }}/{{ __('nav.collapse') }}">
                <summary class="flex cursor-pointer list-none items-center justify-between rounded-lg px-2 py-1 text-xs font-bold uppercase tracking-wide text-slate-400 hover:text-slate-600">
                    {{ __($module['labelKey']) }}
                    <span class="transition-transform group-open:rotate-90" aria-hidden="true">›</span>
                </summary>
                <div class="mt-1 space-y-0.5">
                    @include('partials.nav-items', ['items' => $module['items'], 'messageCount' => $messageCount])
                </div>
            </details>
        @else
            <div class="space-y-0.5">
                @include('partials.nav-items', ['items' => $module['items'], 'messageCount' => $messageCount])
            </div>
        @endif
    </div>
@endforeach
