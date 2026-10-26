@extends('layouts.app')

@section('title', __('messages.libraryTitle'))

@php
    use App\Support\Messaging\Constants;

    $categoryLabel = fn (string $category): string => __('messages.'.(Constants::LIBRARY_CATEGORY_LABEL_KEYS[$category] ?? $category));
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.libraryTitle') }}</h1>
            <a href="{{ route('messages.index') }}" class="btn-secondary">{{ __('messages.backToOutbox') }}</a>
        </div>

        <p class="text-sm text-slate-500">{{ __('messages.librarySubtitle') }}</p>

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

        {{-- New library item --}}
        <form method="POST" action="{{ route('messages.library.store') }}" class="card space-y-3 p-4">
            @csrf
            <h2 class="text-sm font-semibold text-slate-800">{{ __('messages.archiveToLibrary') }}</h2>

            <div>
                <label class="label text-xs" for="lib-title">{{ __('messages.libraryItemTitle') }}</label>
                <input class="input-field text-sm" id="lib-title" name="title" maxlength="120" value="{{ old('title') }}">
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="label text-xs" for="lib-language">{{ __('messages.libraryLanguage') }}</label>
                    <select class="input-field text-sm" id="lib-language" name="language">
                        <option value="en" @selected(old('language', 'en') === 'en')>English</option>
                        <option value="am" @selected(old('language') === 'am')>አማርኛ</option>
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="lib-category">{{ __('messages.libraryCategory') }}</label>
                    <select class="input-field text-sm" id="lib-category" name="category">
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(old('category', 'OTHER') === $category)>{{ $categoryLabel($category) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="label text-xs" for="lib-body">{{ __('messages.customMessage') }}</label>
                <textarea class="input-field text-sm" id="lib-body" name="body" rows="3" maxlength="1000">{{ old('body') }}</textarea>
                @error('body')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
        </form>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('common.name') }}</th>
                        <th class="px-3 py-2">{{ __('messages.customMessage') }}</th>
                        <th class="px-3 py-2">{{ __('messages.libraryCategory') }}</th>
                        <th class="px-3 py-2">{{ __('messages.libraryLanguage') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">
                                <p class="font-medium text-slate-900">{{ $row['title'] }}</p>
                                <p class="text-xs text-slate-500">{{ __t('messages.usedTimes', ['count' => $row['useCount']]) }}</p>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-700">{{ $row['body'] }}</td>
                            <td class="px-3 py-2 text-xs">{{ $categoryLabel($row['category']) }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['language'] === 'am' ? 'አማርኛ' : 'English' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex items-center justify-end gap-2">
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('messages.library.toggle', $row['id']) }}">
                                            @csrf
                                            <input type="hidden" name="field" value="isFavorite">
                                            <input type="hidden" name="value" value="{{ $row['isFavorite'] ? 'false' : 'true' }}">
                                            <button type="submit" class="text-xs text-brand-700 hover:underline">{{ __('messages.addToFavorites') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('messages.library.toggle', $row['id']) }}">
                                            @csrf
                                            <input type="hidden" name="field" value="active">
                                            <input type="hidden" name="value" value="false">
                                            <button type="submit" class="text-xs text-red-600 hover:underline">{{ __('messages.libraryArchive') }}</button>
                                        </form>
                                    @endif
                                    <a href="{{ route('messages.index') }}" class="text-xs text-brand-700 hover:underline">{{ __('messages.reuseThis') }}</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">{{ __('messages.libraryNoItems') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
