@extends('layouts.app')

@section('title', __('hr.scheduleTitle'))

@php
    use App\Support\ClinicTime;
    use App\Support\Hr\Constants;

    $weekdayKey = fn (string $dateKey): string => 'hr.'.Constants::WEEKDAY_LABEL_KEYS[
        (int) (new \DateTimeImmutable($dateKey.'T00:00:00Z'))->format('w')
    ];

    // Group the shifts by the clinic-local day they start on.
    $byDay = [];
    foreach ($shifts as $shift) {
        $key = ClinicTime::clinicDateKey($shift['startsAt'], $timezone);
        $byDay[$key][] = $shift;
    }

    $timeOf = fn ($instant): string => ClinicTime::clinicTimeKey($instant, $timezone);
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('hr.scheduleTitle') }}</h1>
            <div class="flex items-center gap-2 text-sm">
                <a href="{{ route('schedule.index', ['week' => $prevKey]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.previous') }}</a>
                <a href="{{ route('schedule.index', ['week' => $todayKey]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('hr.today') }}</a>
                <a href="{{ route('schedule.index', ['week' => $nextKey]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.next') }}</a>
            </div>
        </div>

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

        <p class="text-sm text-slate-500">
            {{ __('hr.week') }}: {{ $startKey }} → {{ end($days) }}
        </p>

        {{-- Weekly grid --}}
        <div class="card overflow-x-auto p-0">
            <table class="w-full min-w-[56rem] text-sm">
                <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                    <tr>
                        @foreach ($days as $day)
                            <th class="px-3 py-2 {{ $day === $todayKey ? 'text-brand-700' : '' }}">
                                {{ __($weekdayKey($day)) }}<span class="block font-normal normal-case text-slate-400">{{ $day }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        @foreach ($days as $day)
                            <td class="align-top px-3 py-2 {{ $day === $todayKey ? 'bg-brand-50/40' : '' }}">
                                @forelse ($byDay[$day] ?? [] as $shift)
                                    <div class="mb-2 rounded-lg border border-brand-100 bg-white p-2 shadow-sm">
                                        <p class="text-xs font-semibold text-slate-900">{{ $shift['staffName'] }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ $timeOf($shift['startsAt']) }}–{{ $timeOf($shift['endsAt']) }}
                                            @if (ClinicTime::clinicDateKey($shift['endsAt'], $timezone) !== $shift['startsAt']->format('Y-m-d'))
                                                <span class="text-amber-600">+1</span>
                                            @endif
                                        </p>
                                        @if ($shift['note'])
                                            <p class="mt-1 text-xs text-slate-500">{{ $shift['note'] }}</p>
                                        @endif
                                        @if ($canManage)
                                            <div class="mt-1 flex items-center gap-2 text-xs">
                                                <a href="{{ route('schedule.index', ['week' => $startKey, 'edit' => $shift['id']]) }}" class="text-brand-700 hover:underline">{{ __('common.edit') }}</a>
                                                <form method="POST" action="{{ route('schedule.shifts.destroy', $shift['id']) }}" onsubmit="return confirm('{{ __('hr.deleteShiftConfirm') }}')">
                                                    @csrf
                                                    <button type="submit" class="text-red-600 hover:underline">{{ __('common.delete') }}</button>
                                                </form>
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <span class="text-xs text-slate-300">—</span>
                                @endforelse
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>

        @if (count($shifts) === 0)
            <p class="text-sm text-slate-500">{{ __('hr.noShifts') }}</p>
        @endif

        @if ($canManage)
            @include('hr.partials.shift-form', [
                'staffOptions' => $staffOptions,
                'startKey' => $startKey,
                'timezone' => $timezone,
            ])

            @include('hr.partials.template-panel', [
                'staffOptions' => $staffOptions,
                'templates' => $templates,
                'startKey' => $startKey,
            ])
        @endif
    </div>
@endsection
