@extends('layouts.app')

@section('title', __('users.title'))

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('users.title') }}</h1>
            <a href="{{ route('users.create') }}" class="btn-primary">{{ __('users.newUser') }}</a>
        </div>

        @if (session('tempPassword'))
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="font-semibold">{{ __('users.tempPassword') }}</p>
                <p class="mt-1 font-mono text-lg">{{ session('tempPassword') }}</p>
                <p class="mt-1 text-xs">{{ __('users.tempPasswordHint') }}</p>
            </div>
        @endif

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.'.session('actionError')) }}
            </div>
        @endif

        <form method="GET" action="{{ route('users.index') }}" class="card flex flex-wrap items-end gap-3 p-3">
            <div class="flex-1">
                <label class="label text-xs" for="q">{{ __('common.search') }}</label>
                <input class="input-field text-sm" id="q" name="q" type="search" maxlength="100" value="{{ $q }}"
                       placeholder="{{ __('users.searchPlaceholder') }}">
            </div>
            <div>
                <label class="label text-xs" for="role">{{ __('users.roleFilter') }}</label>
                <select class="input-field text-sm" id="role" name="role">
                    <option value="" @selected($role === '')>{{ __('common.all') }}</option>
                    @foreach (['OWNER', 'RECEPTION', 'STAFF'] as $value)
                        <option value="{{ $value }}" @selected($role === $value)>{{ __('common.role'.ucfirst(strtolower($value))) }}</option>
                    @endforeach
                </select>
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
                        <th class="px-3 py-2">{{ __('common.name') }}</th>
                        <th class="px-3 py-2">{{ __('common.email') }}</th>
                        <th class="px-3 py-2">{{ __('common.role') }}</th>
                        <th class="px-3 py-2">{{ __('common.status') }}</th>
                        <th class="px-3 py-2">{{ __('users.lastLogin') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="px-3 py-2 font-medium text-slate-900">{{ $row['name'] }}</td>
                            <td class="px-3 py-2 text-xs text-slate-600">{{ $row['email'] }}</td>
                            <td class="px-3 py-2 text-xs">{{ __('common.role'.ucfirst(strtolower($row['role']))) }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $row['active'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $row['active'] ? __('common.active') : __('common.inactive') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-500">{{ $row['lastLoginAt']?->toDateTimeString() ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <a href="{{ route('users.edit', $row['id']) }}" class="text-xs text-brand-700 hover:underline">{{ __('common.edit') }}</a>

                                    <form method="POST" action="{{ route('users.status', $row['id']) }}"
                                          onsubmit="return confirm('{{ $row['active'] ? __('users.deactivateConfirm') : __('users.reactivate') }}')">
                                        @csrf
                                        <input type="hidden" name="active" value="{{ $row['active'] ? 'false' : 'true' }}">
                                        <button type="submit" class="text-xs {{ $row['active'] ? 'text-red-600' : 'text-emerald-700' }} hover:underline">
                                            {{ $row['active'] ? __('users.deactivate') : __('users.reactivate') }}
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('users.reset', $row['id']) }}"
                                          onsubmit="return confirm('{{ __('users.resetConfirm') }}')">
                                        @csrf
                                        <button type="submit" class="text-xs text-amber-700 hover:underline">{{ __('users.resetPassword') }}</button>
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
            {{ __('common.showing', ['from' => $rows === [] ? 0 : (($page - 1) * \App\Http\Controllers\UserController::PAGE_SIZE) + 1, 'to' => (($page - 1) * \App\Http\Controllers\UserController::PAGE_SIZE) + count($rows), 'total' => $total]) }}
        </p>

        <nav class="flex items-center justify-center gap-3 text-sm">
            @if ($page > 1)
                <a href="{{ route('users.index', array_merge(request()->query(), ['page' => $page - 1])) }}" class="btn-secondary px-3 py-1">{{ __('common.previous') }}</a>
            @endif
            <span class="text-slate-500">{{ __('common.page') }} {{ $page }} {{ __('common.of') }} {{ $pageCount }}</span>
            @if ($page < $pageCount)
                <a href="{{ route('users.index', array_merge(request()->query(), ['page' => $page + 1])) }}" class="btn-secondary px-3 py-1">{{ __('common.next') }}</a>
            @endif
        </nav>
    </div>
@endsection
