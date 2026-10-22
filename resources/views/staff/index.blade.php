@extends('layouts.app')

@section('title', __('nav.staff'))

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('nav.staff') }}</h1>
            <a href="{{ route('staff.create') }}" class="btn-primary">{{ __('common.create') }}</a>
        </div>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.'.session('actionError')) }}
            </div>
        @endif

        <form method="GET" action="{{ route('staff.index') }}" class="card flex flex-wrap items-end gap-3 p-3">
            <div class="flex-1">
                <label class="label text-xs" for="q">{{ __('common.search') }}</label>
                <input class="input-field text-sm" id="q" name="q" type="search" maxlength="100" value="{{ $q }}">
            </div>
            <div>
                <label class="label text-xs" for="status">{{ __('users.statusFilter') }}</label>
                <select class="input-field text-sm" id="status" name="status">
                    <option value="" @selected($status === '')>{{ __('common.all') }}</option>
                    <option value="true" @selected($status === 'true')>{{ __('common.active') }}</option>
                    <option value="false" @selected($status === 'false')>{{ __('common.inactive') }}</option>
                </select>
            </div>
            <button type="submit" class="btn-secondary px-3 py-1.5 text-sm">{{ __('common.filter') }}</button>
        </form>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __('staff.photo') }}</th>
                        <th class="px-3 py-2">{{ __('common.name') }}</th>
                        <th class="px-3 py-2">{{ __('hr.staffLabel') }}</th>
                        <th class="px-3 py-2">{{ __('appointments.service') }}</th>
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2">
                                @php($photo = stored_photo_url($row['photoUrl']))
                                @if ($photo)
                                    <img src="{{ $photo }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">
                                @else
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700" aria-hidden="true">
                                        {{ mb_strtoupper(mb_substr($row['fullName'], 0, 1)) }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-medium text-slate-900">{{ $row['fullName'] }}</td>
                            <td class="px-3 py-2 text-xs text-slate-600">{{ $row['jobTitle'] }}</td>
                            <td class="px-3 py-2 text-xs">{{ $row['specialty'] ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $row['active'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $row['active'] ? __('common.active') : __('common.inactive') }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('staff.edit', $row['id']) }}" class="text-xs text-brand-700 hover:underline">{{ __('common.edit') }}</a>
                                    <form method="POST" action="{{ route('staff.status', $row['id']) }}">
                                        @csrf
                                        <input type="hidden" name="active" value="{{ $row['active'] ? 'false' : 'true' }}">
                                        <button type="submit" class="text-xs {{ $row['active'] ? 'text-red-600' : 'text-emerald-700' }} hover:underline">
                                            {{ $row['active'] ? __('users.deactivate') : __('users.reactivate') }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-sm text-slate-500">
            {{ __('common.showing', ['from' => $rows === [] ? 0 : (($page - 1) * \App\Http\Controllers\StaffController::PAGE_SIZE) + 1, 'to' => (($page - 1) * \App\Http\Controllers\StaffController::PAGE_SIZE) + count($rows), 'total' => $total]) }}
        </p>
    </div>
@endsection
