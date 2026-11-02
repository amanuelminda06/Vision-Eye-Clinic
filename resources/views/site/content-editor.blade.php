@extends('layouts.app')

@section('title', __('content.title'))

@php
    use App\Support\PublicSite\Content;

    $value = function (string $key, string $lang) use ($current): string {
        return $current[$key][$lang] ?? '';
    };
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('content.title') }}</h1>
            <a href="/" target="_blank" class="btn-secondary">{{ __('content.viewSite') }}</a>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        <form method="POST" action="{{ route('site.content.store') }}" class="space-y-5">
            @csrf

            @foreach ($keys as $key)
                <section class="card space-y-3 p-4">
                    <h2 class="text-sm font-semibold text-slate-800">{{ $key }}</h2>

                    @if (in_array($key, ['announcements_items', 'faq_items', 'testimonials_items'], true))
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label text-xs" for="{{ $key }}_en">{{ __('content.en') }}</label>
                                <textarea class="input-field font-mono text-xs" id="{{ $key }}_en" name="{{ $key }}_en" rows="4">{{ $value($key, 'en') }}</textarea>
                            </div>
                            <div>
                                <label class="label text-xs" for="{{ $key }}_am">{{ __('content.am') }}</label>
                                <textarea class="input-field font-mono text-xs" id="{{ $key }}_am" name="{{ $key }}_am" rows="4">{{ $value($key, 'am') }}</textarea>
                            </div>
                        </div>
                    @elseif ($key === 'announcement_active')
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="announcement_active" value="true" @checked($value($key, 'en') === 'true')>
                            {{ __('content.announcementActive') }}
                        </label>
                    @elseif (in_array($key, ['announcement_start', 'announcement_end'], true))
                        @php
                            $dateLabel = $key === 'announcement_start'
                                ? __('content.announcementStart')
                                : __('content.announcementEnd');
                            $min = $key === 'announcement_end' ? $value('announcement_start', 'en') : '';
                            $max = $key === 'announcement_start' ? $value('announcement_end', 'en') : '';
                        @endphp
                        <div>
                            <label class="label text-xs" for="{{ $key }}_en">{{ $dateLabel }}</label>
                            <input type="date" class="input-field text-sm" id="{{ $key }}_en" name="{{ $key }}_en"
                                   value="{{ $value($key, 'en') }}"
                                   @if ($min !== '') min="{{ $min }}" @endif
                                   @if ($max !== '') max="{{ $max }}" @endif>
                            @if ($error = $errors->first($key.'_en'))
                                <p class="mt-1 text-xs text-red-600">{{ field_error($error) }}</p>
                            @endif
                            {{-- The window is global; the Amharic half is kept as-is. --}}
                            <input type="hidden" name="{{ $key }}_am" value="{{ $value($key, 'am') }}">
                        </div>
                    @else
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label text-xs" for="{{ $key }}_en">{{ __('content.en') }}</label>
                                <textarea class="input-field text-sm" id="{{ $key }}_en" name="{{ $key }}_en" rows="3">{{ $value($key, 'en') }}</textarea>
                            </div>
                            <div>
                                <label class="label text-xs" for="{{ $key }}_am">{{ __('content.am') }}</label>
                                <textarea class="input-field text-sm" id="{{ $key }}_am" name="{{ $key }}_am" rows="3">{{ $value($key, 'am') }}</textarea>
                            </div>
                        </div>
                    @endif
                </section>
            @endforeach

            <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
        </form>
    </div>
@endsection
@push('scripts')
<script>
    (function () {
        var start = document.getElementById('announcement_start_en');
        var end = document.getElementById('announcement_end_en');

        if (!start || !end) {
            return;
        }

        function sync() {
            if (start.value) {
                end.min = start.value;
            } else {
                end.removeAttribute('min');
            }

            if (end.value) {
                start.max = end.value;
            } else {
                start.removeAttribute('max');
            }

            if (end.min && end.value && end.value < end.min) {
                end.value = '';
            }

            if (start.max && start.value && start.value > start.max) {
                start.value = '';
            }
        }

        start.addEventListener('change', sync);
        end.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
