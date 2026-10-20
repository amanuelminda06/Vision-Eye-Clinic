@php
    use App\Support\Appointments\Constants;
    use App\Support\ClinicTime;

    $statusLabel = fn (string $status): string => __('appointments.'.Constants::APPOINTMENT_STATUS_LABEL_KEYS[$status] ?? $status);

    $statusClass = fn (string $status): string => match ($status) {
        'SCHEDULED' => 'bg-slate-100 text-slate-600',
        'CONFIRMED' => 'bg-emerald-50 text-emerald-700',
        'COMPLETED' => 'bg-blue-50 text-blue-700',
        'NO_SHOW' => 'bg-amber-50 text-amber-700',
        default => 'bg-red-50 text-red-700',
    };
@endphp

@if (count($appointments) === 0)
    <div class="card p-5 text-sm text-slate-600">{{ __('appointments.patientNoAppointments') }}</div>
@else
    <div class="card overflow-x-auto p-5">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs uppercase text-slate-500">
                    <th class="py-2 pr-3 font-medium">{{ __('appointments.startsAt') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ __('appointments.doctor') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ __('appointments.service') }}</th>
                    <th class="py-2 pr-3 font-medium">{{ __('appointments.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($appointments as $row)
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="py-2 pr-3 text-slate-800">{{ ClinicTime::formatInTz($row['startsAt'], $timezone) }}</td>
                        <td class="py-2 pr-3 text-slate-700">{{ $row['doctorName'] }}</td>
                        <td class="py-2 pr-3 text-slate-600">{{ $row['serviceName'] ?? '—' }}</td>
                        <td class="py-2 pr-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">
                                {{ $statusLabel($row['status']) }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
