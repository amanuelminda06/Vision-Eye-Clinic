@extends('layouts.app')

@section('title', __('hr.leaveTitle'))

@php
    use App\Support\Hr\Constants;
    use App\Support\Hr\Leave;
    use App\Support\Hr\Shifts;

    $statusLabel = fn (string $status): string => __('hr.'.Constants::LEAVE_STATUS_LABEL_KEYS[$status] ?? $status);
    $typeLabel = fn (string $type): string => __('hr.'.Constants::LEAVE_TYPE_LABEL_KEYS[$type] ?? $type);

    $statusClass = fn (string $status): string => match ($status) {
        'APPROVED' => 'bg-emerald-50 text-emerald-700',
        'REJECTED' => 'bg-red-50 text-red-700',
        'CANCELLED' => 'bg-slate-100 text-slate-600',
        default => 'bg-amber-50 text-amber-700',
    };
@endphp

@section('content')
    <div class="space-y-5">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('hr.leaveTitle') }}</h1>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('hr.'.session('actionError')) }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-3">
            {{-- Request form --}}
            <section class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('hr.requestLeave') }}</h2>

                @if ($myStaffId === null)
                    <p class="text-sm text-amber-700">{{ __('hr.noStaff') }}</p>
                @else
                    <form method="POST" action="{{ route('leave.store') }}" class="space-y-3">
                        @csrf

                        @if ($canRequestForOthers)
                            <div>
                                <label class="label text-xs" for="leave-staffId">{{ __('hr.staffLabel') }}</label>
                                <select class="input-field text-sm" id="leave-staffId" name="staffId">
                                    <option value="">{{ __('hr.selectStaff') }}</option>
                                    @foreach ($staffOptions as $staff)
                                        <option value="{{ $staff->id }}" @selected(old('staffId') === $staff->id)>{{ $staff->full_name }}</option>
                                    @endforeach
                                </select>
                                @error('staffId')<p class="mt-1 text-xs text-red-600">{{ field_error('staffId') }}</p>@enderror
                            </div>
                        @else
                            <input type="hidden" name="staffId" value="{{ $myStaffId }}">
                        @endif

                        <div>
                            <label class="label text-xs" for="leave-type">{{ __('hr.leaveType') }}</label>
                            <select class="input-field text-sm" id="leave-type" name="type">
                                @foreach (Constants::LEAVE_TYPES as $type)
                                    <option value="{{ $type }}" @selected(old('type', 'ANNUAL') === $type)>{{ $typeLabel($type) }}</option>
                                @endforeach
                            </select>
                            @error('type')<p class="mt-1 text-xs text-red-600">{{ field_error('type') }}</p>@enderror
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label text-xs" for="leave-startDate">{{ __('hr.startDate') }}</label>
                                <input class="input-field text-sm" id="leave-startDate" name="startDate" type="date" value="{{ old('startDate', $todayKey) }}">
                                @error('startDate')<p class="mt-1 text-xs text-red-600">{{ field_error('startDate') }}</p>@enderror
                            </div>
                            <div>
                                <label class="label text-xs" for="leave-endDate">{{ __('hr.endDate') }}</label>
                                <input class="input-field text-sm" id="leave-endDate" name="endDate" type="date" value="{{ old('endDate', $todayKey) }}">
                                @error('endDate')<p class="mt-1 text-xs text-red-600">{{ field_error('endDate') }}</p>@enderror
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="halfDay" value="true" @checked(old('halfDay') === 'true')>
                            {{ __('hr.halfDay') }}
                        </label>
                        <p class="-mt-2 text-xs text-slate-500">{{ __('hr.halfDayHint') }}</p>

                        <div>
                            <label class="label text-xs" for="leave-reason">{{ __('hr.reason') }}</label>
                            <textarea class="input-field text-sm" id="leave-reason" name="reason" rows="2" maxlength="300">{{ old('reason') }}</textarea>
                            @error('reason')<p class="mt-1 text-xs text-red-600">{{ field_error('reason') }}</p>@enderror
                        </div>

                        <button type="submit" class="btn-primary w-full">{{ __('hr.submitRequest') }}</button>
                    </form>
                @endif
            </section>

            {{-- Balance card --}}
            <section class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('hr.balance') }}</h2>

                @if ($balance === null || $balance['entitlement'] === null)
                    <p class="text-sm text-slate-600">{{ __('hr.balanceUnset') }}</p>
                @else
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">{{ __('hr.balanceEntitlement') }}</dt>
                            <dd class="font-medium tabular-nums">{{ $balance['entitlement'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">{{ __('hr.balanceTaken') }}</dt>
                            <dd class="font-medium tabular-nums">{{ $balance['taken'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">{{ __('hr.balanceRemaining') }}</dt>
                            <dd class="font-medium tabular-nums">{{ $balance['remaining'] }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-slate-500">{{ __('hr.balanceHint') }}</p>
                @endif
            </section>

            {{-- My requests --}}
            <section class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-800">{{ __('hr.myLeave') }}</h2>

                @if ($requests === [])
                    <p class="text-sm text-slate-500">{{ __('hr.noLeave') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="py-2 pr-3 font-medium">{{ __('hr.startDate') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('hr.endDate') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('hr.days') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('hr.leaveType') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('common.status') }}</th>
                                    <th class="py-2 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($requests as $request)
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <td class="py-2 pr-3">{{ Shifts::dateColumnToKey($request['startDate']) }}</td>
                                        <td class="py-2 pr-3">{{ Shifts::dateColumnToKey($request['endDate']) }}</td>
                                        <td class="py-2 pr-3 tabular-nums">
                                            {{ Leave::requestedDays(['startDate' => $request['startDate'], 'endDate' => $request['endDate'], 'halfDay' => $request['halfDay']]) }}
                                        </td>
                                        <td class="py-2 pr-3">{{ $typeLabel($request['type']) }}</td>
                                        <td class="py-2 pr-3">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($request['status']) }}">{{ $statusLabel($request['status']) }}</span>
                                        </td>
                                        <td class="py-2 text-right">
                                            @if ($request['status'] === 'REQUESTED' || $request['status'] === 'APPROVED')
                                                <form method="POST" action="{{ route('leave.cancel', $request['id']) }}" onsubmit="return confirm('{{ __('hr.cancelConfirm') }}')">
                                                    @csrf
                                                    <input type="hidden" name="leaveId" value="{{ $request['id'] }}">
                                                    <button type="submit" class="text-xs text-red-600 hover:underline">{{ __('hr.cancelRequest') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="flex items-center gap-2">
            @include('partials.export-links', [
                'base' => route('leave.export'),
                'query' => '',
            ])
        </div>
    </div>
@endsection
