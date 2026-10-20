@extends('layouts.app')

@section('title', __('feedback.title'))

@section('content')
    <div class="space-y-5">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('feedback.title') }}</h1>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.unknownError') }}
            </div>
        @endif

        <form method="GET" action="{{ route('site.feedback') }}" class="card flex flex-wrap items-end gap-3 p-3">
            <div class="flex-1">
                <label class="label text-xs" for="q">{{ __('common.search') }}</label>
                <input class="input-field text-sm" id="q" name="q" type="search" maxlength="100" value="{{ $q }}">
            </div>
            <div>
                <label class="label text-xs" for="website">{{ __('common.status') }}</label>
                <select class="input-field text-sm" id="website" name="website">
                    <option value="" @selected($website === '')>{{ __('common.all') }}</option>
                    <option value="true" @selected($website === 'true')>{{ __('common.active') }}</option>
                    <option value="false" @selected($website === 'false')>{{ __('common.inactive') }}</option>
                </select>
            </div>
            <button type="submit" class="btn-secondary px-3 py-1.5 text-sm">{{ __('common.filter') }}</button>
        </form>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('common.name') }}</th>
                        <th class="px-3 py-2">{{ __('site.feedback.email') }}</th>
                        <th class="px-3 py-2">{{ __('site.feedback.rating') }}</th>
                        <th class="px-3 py-2">{{ __('site.feedback.message') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2 font-medium text-slate-900">{{ $row['name'] }}</td>
                            <td class="px-3 py-2 text-xs text-slate-600">{{ $row['email'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['rating'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-700">{{ $row['text'] }}</td>
                            <td class="px-3 py-2">
                                <div class="flex items-center justify-end gap-2">
                                    <form method="POST" action="{{ route('site.feedback.toggle', $row['id']) }}">
                                        @csrf
                                        <input type="hidden" name="showOnWebsite" value="{{ $row['showOnWebsite'] ? 'false' : 'true' }}">
                                        <button type="submit" class="text-xs text-brand-700 hover:underline">
                                            {{ $row['showOnWebsite'] ? __('common.inactive') : __('common.active') }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('site.feedback.destroy', $row['id']) }}">
                                        @csrf
                                        <button type="submit" class="text-xs text-red-600 hover:underline">{{ __('common.delete') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
