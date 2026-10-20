@extends('layouts.app')

@section('title', __('services.title'))

@php
    use App\Support\Services\Categories;

    $buildQuery = function (array $patch = []) use ($filters): string {
        $merged = array_merge($filters, $patch);
        $params = [];
        if (! empty($merged['q'])) $params['q'] = $merged['q'];
        if (! empty($merged['category'])) $params['category'] = $merged['category'];
        if (! empty($merged['status'])) $params['status'] = $merged['status'];
        return $params === [] ? '' : '?'.http_build_query($params);
    };
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('services.title') }}</h1>
            <a href="{{ route('services.create') }}" class="btn-primary">{{ __('services.newService') }}</a>
        </div>

        <form method="get" action="{{ route('services.index') }}" class="card grid grid-cols-1 gap-2 p-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="label text-xs" for="q">{{ __('common.search') }}</label>
                <input class="input-field text-sm" id="q" name="q" type="search" maxlength="100"
                       value="{{ $filters['q'] }}" placeholder="{{ __('services.searchPlaceholder') }}">
            </div>
            <div>
                <label class="label text-xs" for="category">{{ __('services.category') }}</label>
                <select class="input-field text-sm" id="category" name="category">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach (Categories::SERVICE_CATEGORIES as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>
                            {{ __('services.'.Categories::labelKey($category)) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label text-xs" for="status">{{ __('common.status') }}</label>
                <select class="input-field text-sm" id="status" name="status">
                    <option value="" @selected($filters['status'] === '')>{{ __('common.all') }}</option>
                    <option value="true" @selected($filters['status'] === 'true')>{{ __('common.active') }}</option>
                    <option value="false" @selected($filters['status'] === 'false')>{{ __('common.inactive') }}</option>
                </select>
            </div>
            <div class="flex items-end gap-2 sm:col-span-4">
                <button type="submit" class="btn-secondary px-3 py-1.5 text-sm">{{ __('common.filter') }}</button>
                <a href="{{ route('services.index') }}" class="pb-1 text-xs text-slate-500 hover:text-slate-800">{{ __('common.clear') }}</a>
            </div>
        </form>

        <div class="card overflow-hidden p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('services.name') }}</th>
                        <th class="px-3 py-2">{{ __('services.category') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('services.currentPrice') }}</th>
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $service)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">
                                <a href="{{ route('services.edit', $service->id) }}" class="font-medium text-brand-800 hover:underline">{{ $service->name }}</a>
                                @if ($service->name_am)
                                    <span class="block text-xs text-slate-500">{{ $service->name_am }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ __('services.'.Categories::labelKey($service->category)) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ format_money($service->current_price) }}</td>
                            <td class="px-3 py-2">
                                @if ($service->active)
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('common.active') }}</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ __('common.inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('services.edit', $service->id) }}" class="text-brand-700 hover:underline">{{ __('common.edit') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
