@extends('layouts.app')

@section('title', __('appointments.title'))

@php
    use App\Support\Appointments\ApptRoles;
    use App\Support\Appointments\Constants;
    use App\Support\Appointments\Logic;
    use App\Support\ClinicTime;

    $statusLabel = fn (string $status): string => __('appointments.'.Constants::APPOINTMENT_STATUS_LABEL_KEYS[$status] ?? $status);
    $sourceLabel = fn (string $source): string => __('appointments.'.Constants::APPOINTMENT_SOURCE_LABEL_KEYS[$source] ?? $source);

    $statusClass = fn (string $status): string => match ($status) {
        'SCHEDULED' => 'bg-slate-100 text-slate-600',
        'CONFIRMED' => 'bg-emerald-50 text-emerald-700',
        'COMPLETED' => 'bg-blue-50 text-blue-700',
        'NO_SHOW' => 'bg-amber-50 text-amber-700',
        default => 'bg-red-50 text-red-700',
    };

    // The status buttons offered for a row (same list as the legacy UI).
    $nextStatuses = function (string $current) use ($canOverride): array {
        return array_values(array_filter(
            Constants::APPOINTMENT_STATUSES,
            fn (string $next): bool => Logic::transitionAllowed($current, $next, ['ownerOverride' => $canOverride]),
        ));
    };
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('appointments.title') }}</h1>
            <div class="flex flex-wrap items-center gap-2">
                @include('partials.export-links', [
                    'base' => route('appointments.export'),
                    'query' => '?view='.$view.'&day='.$dayKey,
                ])
                <a href="{{ route('appointments.create') }}" class="btn-primary">{{ __('appointments.newAppointment') }}</a>
            </div>
        </div>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('appointments.'.session('actionError')) }}
            </div>
        @endif

        @if (session('warnings'))
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                @foreach ((array) session('warnings') as $warning)
                    <p>{{ __('appointments.warning.'.$warning) }}</p>
                @endforeach
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        {{-- View switcher + navigation --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                @foreach (['list' => 'viewList', 'day' => 'viewDay', 'week' => 'viewWeek'] as $key => $labelKey)
                    <a href="{{ route('appointments.index', ['view' => $key, 'day' => $dayKey, 'doctor' => $doctorId]) }}"
                       class="{{ $view === $key ? 'btn-primary' : 'btn-secondary' }} text-sm">{{ __('appointments.'.$labelKey) }}</a>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('appointments.index', ['view' => $view, 'day' => $prevKey, 'doctor' => $doctorId]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.previous') }}</a>
                <a href="{{ route('appointments.index', ['view' => $view, 'day' => $todayKey, 'doctor' => $doctorId]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('appointments.today') }}</a>
                <a href="{{ route('appointments.index', ['view' => $view, 'day' => $nextKey, 'doctor' => $doctorId]) }}" class="btn-secondary px-3 py-1 text-sm">{{ __('common.next') }}</a>

                <form method="GET" action="{{ route('appointments.index') }}" class="flex items-center gap-2">
                    <input type="hidden" name="view" value="{{ $view }}">
                    <input type="hidden" name="day" value="{{ $dayKey }}">
                    <select class="input-field text-sm" name="doctor" onchange="this.form.submit()">
                        <option value="">{{ __('appointments.allDoctors') }}</option>
                        @foreach ($doctors as $doctor)
                            <option value="{{ $doctor->id }}" @selected($doctorId === $doctor->id)>{{ $doctor->full_name }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>

        @if ($view === 'week')
            {{-- Week view: seven day cards --}}
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                @foreach ($days as $day)
                    <div class="card p-3">
                        <h3 class="text-sm font-semibold text-slate-800">
                            {{ ClinicTime::clinicDateKey(\DateTimeImmutable::createFromFormat('Y-m-d', $day)->format('Y-m-d'), $timezone) }}
                            <span class="block text-xs font-normal text-slate-500">{{ $day }}</span>
                        </h3>
                        <ul class="mt-2 space-y-2">
                            @forelse ($byDay[$day] ?? [] as $row)
                                <li class="rounded-lg border border-brand-100 p-2 text-xs">
                                    <p class="font-medium text-slate-900">
                                        {{ ClinicTime::clinicTimeKey($row['startsAt'], $timezone) }} · {{ $row['patientName'] }}
                                    </p>
                                    <p class="text-slate-500">
                                        {{ $row['doctorName'] }}@if ($row['serviceName']) · {{ $row['serviceName'] }}@endif
                                    </p>
                                    <span class="mt-1 inline-block rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $statusClass($row['status']) }}">
                                        {{ $statusLabel($row['status']) }}
                                    </span>
                                </li>
                            @empty
                                <li class="text-xs text-slate-400">{{ __('appointments.noAppointments') }}</li>
                            @endforelse
                        </ul>
                    </div>
                @endforeach
            </div>
        @else
            {{-- List / day view --}}
            <div class="card overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead class="border-b border-brand-100 bg-brand-50/60 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">{{ __('appointments.when') }}</th>
                            <th class="px-3 py-2">{{ __('appointments.patient') }}</th>
                            <th class="px-3 py-2">{{ __('appointments.doctor') }}</th>
                            <th class="px-3 py-2">{{ __('appointments.service') }}</th>
                            <th class="px-3 py-2">{{ __('appointments.status') }}</th>
                            <th class="px-3 py-2">{{ __('appointments.source') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-b border-brand-50 last:border-0">
                                <td class="px-3 py-2 text-xs text-slate-600">{{ ClinicTime::formatInTz($row['startsAt'], $timezone) }}</td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('patients.show', $row['patientId']) }}" class="text-brand-700 hover:underline">{{ $row['patientName'] }}</a>
                                </td>
                                <td class="px-3 py-2">{{ $row['doctorName'] }}</td>
                                <td class="px-3 py-2">{{ $row['serviceName'] ?? __('appointments.noService') }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">{{ $statusLabel($row['status']) }}</span>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ $sourceLabel($row['createdFrom']) }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap items-center justify-end gap-1">
                                        @if (! in_array($row['status'], ['CANCELLED', 'NO_SHOW'], true))
                                            <a href="{{ route('appointments.edit', $row['id']) }}" class="text-xs text-brand-700 hover:underline">{{ __('appointments.edit') }}</a>
                                        @endif

                                        @foreach ($nextStatuses($row['status']) as $next)
                                            @if ($next === 'CANCELLED')
                                                <button type="button" class="btn-danger px-2 py-1 text-xs" data-open-modal="cancel-{{ $row['id'] }}">{{ __('appointments.action.'.$next) }}</button>
                                            @elseif ($next === 'NO_SHOW')
                                                <form method="POST" action="{{ route('appointments.status', $row['id']) }}" onsubmit="return confirm('{{ __('appointments.confirmDestructive') }}')">
                                                    @csrf
                                                    <input type="hidden" name="id" value="{{ $row['id'] }}">
                                                    <input type="hidden" name="status" value="NO_SHOW">
                                                    <button type="submit" class="btn-secondary px-2 py-1 text-xs">{{ __('appointments.action.'.$next) }}</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('appointments.status', $row['id']) }}">
                                                    @csrf
                                                    <input type="hidden" name="id" value="{{ $row['id'] }}">
                                                    <input type="hidden" name="status" value="{{ $next }}">
                                                    <button type="submit" class="btn-primary px-2 py-1 text-xs">{{ __('appointments.action.'.$next) }}</button>
                                                </form>
                                            @endif
                                        @endforeach

                                        @if ($row['status'] === 'COMPLETED')
                                            <a href="{{ route('followups.create', ['patientId' => $row['patientId'], 'appointmentId' => $row['id']]) }}" class="text-xs text-brand-700 hover:underline">{{ __('appointments.createFollowUp') }}</a>
                                            <a href="{{ route('sales.create', ['patientId' => $row['patientId'], 'doctorId' => $row['doctorId'], 'serviceId' => $row['serviceId']]) }}" class="text-xs text-brand-700 hover:underline">{{ __('appointments.recordSale') }}</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>

                            <tr id="cancel-{{ $row['id'] }}-row" class="hidden">
                                <td colspan="7" class="px-3 py-2">
                                    <form method="POST" action="{{ route('appointments.status', $row['id']) }}" class="flex items-end gap-2">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $row['id'] }}">
                                        <input type="hidden" name="status" value="CANCELLED">
                                        <div class="flex-1">
                                            <label class="label text-xs" for="cancel-reason-{{ $row['id'] }}">{{ __('appointments.cancelReason') }}</label>
                                            <textarea class="input-field text-sm" id="cancel-reason-{{ $row['id'] }}" name="cancelReason" rows="1" required maxlength="300" placeholder="{{ __('appointments.cancelReasonRequired') }}"></textarea>
                                        </div>
                                        <button type="submit" class="btn-danger">{{ __('appointments.cancelAppointment') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">{{ __('common.noResults') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.dataset.openModal.replace('cancel-', '');
            var row = document.getElementById('cancel-' + id + '-row');
            if (row) row.classList.toggle('hidden');
        });
    });
})();
</script>
@endpush
