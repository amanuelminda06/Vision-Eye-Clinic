@extends('layouts.app')

@section('title', __('messages.draftsTitle'))

@section('content')
    <div class="space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.draftsTitle') }}</h1>
            <a href="{{ route('messages.index') }}" class="btn-secondary">{{ __('messages.backToOutbox') }}</a>
        </div>

        <p class="text-sm text-slate-500">{{ __('messages.draftsSubtitle') }}</p>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        {{-- New draft --}}
        <form method="POST" action="{{ route('messages.drafts.store') }}" class="card space-y-3 p-4">
            @csrf
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="label text-xs" for="draft-title">{{ __('messages.libraryItemTitle') }}</label>
                    <input class="input-field text-sm" id="draft-title" name="title" maxlength="120" value="{{ old('title') }}">
                </div>
                <div>
                    <label class="label text-xs" for="draft-language">{{ __('messages.messageLanguage') }}</label>
                    <select class="input-field text-sm" id="draft-language" name="language">
                        <option value="en" @selected(old('language', 'en') === 'en')>English</option>
                        <option value="am" @selected(old('language') === 'am')>አማርኛ</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="label text-xs" for="draft-body">{{ __('messages.customMessage') }}</label>
                <textarea class="input-field text-sm" id="draft-body" name="body" rows="3" maxlength="1000">{{ old('body') }}</textarea>
                @error('body')<p class="text-xs text-red-600">{{ field_error('body') }}</p>@enderror
            </div>

            <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
        </form>

        <div class="card overflow-hidden p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('common.name') }}</th>
                        <th class="px-3 py-2">{{ __('messages.customMessage') }}</th>
                        <th class="px-3 py-2">{{ __('messages.messageLanguage') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2 font-medium text-slate-900">{{ $row['title'] ?: __('messages.draftsUntitled') }}</td>
                            <td class="px-3 py-2 text-xs text-slate-700">{{ $row['body'] }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['language'] === 'am' ? 'አማርኛ' : 'English' }}</td>
                            <td class="px-3 py-2 text-right">
                                <form method="POST" action="{{ route('messages.drafts.destroy', $row['id']) }}">
                                    @csrf
                                    <button type="submit" class="text-xs text-red-600 hover:underline">{{ __('common.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">{{ __('messages.draftsNoDrafts') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
