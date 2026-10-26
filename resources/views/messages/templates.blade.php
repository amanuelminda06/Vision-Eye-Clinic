@extends('layouts.app')

@section('title', __('messages.templatesTitle'))

@php
    use App\Support\Messaging\Constants;

    $label = fn (string $key): string => __('messages.'.(Constants::TEMPLATE_LABEL_KEYS[$key] ?? $key));
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.templatesTitle') }}</h1>
            <a href="{{ route('messages.index') }}" class="btn-secondary">{{ __('messages.backToOutbox') }}</a>
        </div>

        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ $safetyNote }}</p>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('messages.'.session('actionError')) }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        @foreach ($keys as $key)
            @foreach ($languages as $language)
                @php
                    $row = $templates[$key][$language] ?? null;
                    $body = old('body', $row['body'] ?? '');
                    $active = old('active', isset($row['active']) && $row['active'] ? 'true' : 'true');
                @endphp
                <form method="POST" action="{{ route('messages.templates.store') }}" class="card space-y-3 p-4">
                    @csrf
                    <input type="hidden" name="key" value="{{ $key }}">
                    <input type="hidden" name="language" value="{{ $language }}">

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-slate-800">
                            {{ $label($key) }}
                            <span class="ml-1 text-xs font-normal text-slate-500">{{ $language === 'am' ? 'አማርኛ' : 'English' }}</span>
                            @if ($language === 'am')
                                <span class="ml-1 rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-700">{{ __('messages.amDraft') }}</span>
                            @endif
                        </h2>
                        <label class="flex items-center gap-2 text-xs">
                            <input type="checkbox" name="active" value="true" @checked($active === 'true')>
                            {{ __('messages.active') }}
                        </label>
                    </div>

                    <textarea class="input-field text-sm" name="body" rows="4" maxlength="1000">{{ $body }}</textarea>
                    @error('body')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror

                    <div class="flex items-center gap-2">
                        <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
                    </div>
                </form>
            @endforeach
        @endforeach
    </div>
@endsection
