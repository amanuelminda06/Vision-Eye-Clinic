@php
    use App\Support\Nav;
@endphp
@foreach ($items as $item)
    @continue(! in_array(auth()->user()->role, $item['roles'], true))
    <a href="{{ $item['href'] }}"
       class="flex items-center justify-between rounded-lg px-2.5 py-1.5 text-sm {{ Nav::isActive($item['href']) ? 'bg-brand-700 font-semibold text-white' : 'text-slate-700 hover:bg-brand-50' }}"
       @if (Nav::isActive($item['href'])) aria-current="page" @endif>
        <span>{{ __($item['labelKey']) }}</span>
        @if ($item['badge'] === 'messages' && $messageCount > 0)
            <span class="ml-2 inline-flex min-w-5 items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] font-bold text-white">{{ $messageCount }}</span>
        @endif
        @if ($item['badge'] === 'requests' && ($requestCount ?? 0) > 0)
            <span class="ml-2 inline-flex min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[11px] font-bold text-white">{{ $requestCount }}</span>
        @endif
    </a>
@endforeach
