@extends('layouts.app')

@section('title', __('patients.title'))

@php
    use App\Support\Nav;
    use App\Support\Patients\PatientQueries;

    $tc = fn (string $key, array $replace = []) => $replace === [] ? __("common.$key") : __t("common.$key", $replace);

    // Build a /patients URL with the current filters + a patch (omits defaults).
    $buildQuery = function (array $patch = []) use ($filters): string {
        $merged = array_merge($filters, $patch);
        $params = [];
        if (! empty($merged['q'])) $params['q'] = $merged['q'];
        if (! empty($merged['idNo'])) $params['idNo'] = $merged['idNo'];
        if (! empty($merged['dateFrom'])) $params['dateFrom'] = $merged['dateFrom'];
        if (! empty($merged['dateTo'])) $params['dateTo'] = $merged['dateTo'];
        if (! empty($merged['status'])) $params['status'] = $merged['status'];
        if (($merged['sort'] ?? 'fullName') !== 'fullName') $params['sort'] = $merged['sort'];
        if (($merged['dir'] ?? 'asc') !== 'asc') $params['dir'] = $merged['dir'];
        if (($merged['page'] ?? 1) > 1) $params['page'] = (string) $merged['page'];
        return $params === [] ? '' : '?'.http_build_query($params);
    };

    $sortKeyFor = fn (string $column) => ['full_name' => 'fullName', 'birth_date' => 'birthDate', 'created_at' => 'createdAt'][$column] ?? null;
    $activeSort = $filters['sort'];
    $exportQuery = $buildQuery(['page' => 1]);

    $sexLabel = function (?string $sex): string {
        return match ($sex) {
            'MALE' => __('patients.sexMale'),
            'FEMALE' => __('patients.sexFemale'),
            'OTHER' => __('patients.sexOther'),
            default => '—',
        };
    };

    $hasFilters = $filters['q'] !== '' || $filters['dateFrom'] !== '' || $filters['dateTo'] !== '' || $filters['status'] !== '';
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('patients.title') }}</h1>
            <div class="flex items-center gap-2">
                <a href="{{ route('patients.import') }}" class="btn-secondary">{{ __('patients.importCsv') }}</a>
                <a href="{{ route('patients.create') }}" class="btn-primary">{{ __('patients.newPatient') }}</a>
            </div>
        </div>

        <form method="get" action="{{ route('patients.index') }}" class="card grid grid-cols-1 gap-2 p-3 sm:grid-cols-4">
            <div>
                <label class="label text-xs" for="q">{{ $tc('search') }}</label>
                <input class="input-field text-sm" id="q" name="q" type="search" maxlength="100"
                       value="{{ $filters['q'] }}" placeholder="{{ __('patients.searchPlaceholder') }}">
            </div>
            <div>
                <label class="label text-xs" for="idNo">{{ __('patients.idNo') }}</label>
                <input class="input-field text-sm" id="idNo" name="idNo" type="search" maxlength="32"
                       value="{{ $filters['idNo'] }}" placeholder="{{ __('patients.idNoHint') }}">
            </div>
            <div>
                <label class="label text-xs" for="dateFrom">{{ __('patients.dateFrom') }}</label>
                <input class="input-field text-sm" id="dateFrom" name="dateFrom" type="date" value="{{ $filters['dateFrom'] }}">
            </div>
            <div>
                <label class="label text-xs" for="dateTo">{{ __('patients.dateTo') }}</label>
                <input class="input-field text-sm" id="dateTo" name="dateTo" type="date" value="{{ $filters['dateTo'] }}">
            </div>
            <div>
                <label class="label text-xs" for="status">{{ __('patients.statusFilter') }}</label>
                <select class="input-field text-sm" id="status" name="status">
                    <option value="" @selected($filters['status'] === '')>{{ $tc('all') }}</option>
                    <option value="true" @selected($filters['status'] === 'true')>{{ $tc('active') }}</option>
                    <option value="false" @selected($filters['status'] === 'false')>{{ $tc('inactive') }}</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="btn-secondary px-3 py-1.5 text-sm">{{ $tc('filter') }}</button>
                <a href="{{ route('patients.index') }}" class="pb-1 text-xs text-slate-500 hover:text-slate-800">{{ $tc('clear') }}</a>
            </div>
            <div class="flex items-end">
                @include('partials.export-links', [
                    'base' => route('patients.export'),
                    'query' => $exportQuery,
                ])
            </div>
        </form>

        <div class="card overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            <a href="{{ route('patients.index').$buildQuery(['sort' => 'fullName', 'dir' => ($activeSort === 'fullName' && $filters['dir'] === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}" class="hover:text-slate-900">
                                {{ __('patients.fullName') }}@if ($activeSort === 'fullName')<span aria-hidden="true">{{ $filters['dir'] === 'asc' ? ' ↑' : ' ↓' }}</span>@endif
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium">{{ __('patients.idNo') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('patients.phone') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('patients.sex') }}</th>
                        <th class="px-4 py-3 font-medium">
                            <a href="{{ route('patients.index').$buildQuery(['sort' => 'birthDate', 'dir' => ($activeSort === 'birthDate' && $filters['dir'] === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}" class="hover:text-slate-900">
                                {{ __('patients.birthDate') }}@if ($activeSort === 'birthDate')<span aria-hidden="true">{{ $filters['dir'] === 'asc' ? ' ↑' : ' ↓' }}</span>@endif
                            </a>
                        </th>
                        <th class="px-4 py-3 font-medium">{{ __('patients.tagsTitle') }}</th>
                        <th class="px-4 py-3 font-medium">{{ $tc('status') }}</th>
                        <th class="px-4 py-3 font-medium">
                            <a href="{{ route('patients.index').$buildQuery(['sort' => 'createdAt', 'dir' => ($activeSort === 'createdAt' && $filters['dir'] === 'asc') ? 'desc' : 'asc', 'page' => 1]) }}" class="hover:text-slate-900">
                                {{ __('patients.createdCol') }}@if ($activeSort === 'createdAt')<span aria-hidden="true">{{ $filters['dir'] === 'asc' ? ' ↑' : ' ↓' }}</span>@endif
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('patients.show', $row->id) }}" class="font-medium text-slate-900 hover:underline">{{ $row->full_name }}</a>
                                @if ($row->do_not_message)
                                    <span class="ml-2 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{{ __('patients.dnmYes') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $row->id_no ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600"><a href="tel:{{ $row->phone_e164 }}" class="hover:text-slate-900">{{ $row->phone_e164 }}</a></td>
                            <td class="px-4 py-3 text-slate-600">{{ $sexLabel($row->sex) }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $row->birth_date?->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="flex flex-wrap gap-1">
                                    @foreach ($row->tags as $tag)
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium text-white" style="background-color: {{ $tag->color }}">{{ $tag->name }}</span>
                                    @endforeach
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $row->active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $row->active ? $tc('active') : $tc('inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $row->created_at?->format('Y-m-d') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-slate-500">
                                {{ $hasFilters ? $tc('noResults') : $tc('empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
            <span>
                {{ $tc('showing', [
                    'from' => $total === 0 ? 0 : ($page - 1) * $pageSize + 1,
                    'to' => min($page * $pageSize, $total),
                    'total' => $total,
                ]) }}
            </span>
            <div class="flex items-center gap-2">
                @if ($page > 1)
                    <a href="{{ route('patients.index').$buildQuery(['page' => $page - 1]) }}" class="btn-secondary">{{ $tc('previous') }}</a>
                @else
                    <span class="btn-secondary cursor-not-allowed opacity-50">{{ $tc('previous') }}</span>
                @endif
                @if ($page < $pageCount)
                    <a href="{{ route('patients.index').$buildQuery(['page' => $page + 1]) }}" class="btn-secondary">{{ $tc('next') }}</a>
                @else
                    <span class="btn-secondary cursor-not-allowed opacity-50">{{ $tc('next') }}</span>
                @endif
            </div>
        </div>
    </div>
@endsection
