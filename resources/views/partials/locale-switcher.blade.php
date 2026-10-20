@php
    $path = '/'.trim(request()->path(), '/');
    $langParam = app()->getLocale() === 'am' ? 'am' : 'en';
@endphp
<div class="inline-flex items-center gap-1 text-xs font-semibold" role="group" aria-label="{{ __('common.language') }}">
    <a href="{{ request()->fullUrlWithQuery(['lang' => 'en']) }}"
       class="rounded-md px-2 py-1 {{ $langParam === 'en' ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-brand-50' }}"
       aria-pressed="{{ $langParam === 'en' ? 'true' : 'false' }}">EN</a>
    <a href="{{ request()->fullUrlWithQuery(['lang' => 'am']) }}"
       class="rounded-md px-2 py-1 {{ $langParam === 'am' ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-brand-50' }}"
       aria-pressed="{{ $langParam === 'am' ? 'true' : 'false' }}">አማ</a>
</div>
