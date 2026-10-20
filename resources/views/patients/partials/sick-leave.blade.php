@php
    use App\Support\ClinicTime;

    $tv = fn (?string $key) => $key === null ? null : field_error($key);
    $editingStart = old('startDate', $editing?->start_date?->format('Y-m-d') ?? $today);
    $editingEnd = old('endDate', $editing?->end_date?->format('Y-m-d') ?? $today);
@endphp

<div class="space-y-4">
    <h2 class="text-sm font-semibold text-slate-800">{{ __('patients.sickLeaveTitle') }}</h2>

    {{-- Create / edit form --}}
    <form method="POST"
          action="{{ $editing ? route('patients.sick-leave.update', [$patient->id, $editing->id]) : route('patients.sick-leave.store', $patient->id) }}"
          class="card space-y-3 p-5">
        @csrf
        <input type="hidden" name="patientId" value="{{ $patient->id }}">

        <h3 class="text-sm font-semibold text-slate-800">{{ $editing ? __('patients.sickLeaveEdit') : __('patients.sickLeaveNew') }}</h3>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label" for="sick-start">{{ __('patients.sickStartDate') }}</label>
                <input class="input-field" id="sick-start" name="startDate" type="date" max="{{ $today }}"
                       value="{{ $editingStart }}" @if ($errors->has('startDate')) aria-invalid="true" @endif>
                @error('startDate')<p class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>@enderror
            </div>
            <div>
                <label class="label" for="sick-end">{{ __('patients.sickEndDate') }}</label>
                <input class="input-field" id="sick-end" name="endDate" type="date" max="{{ $today }}"
                       value="{{ $editingEnd }}" @if ($errors->has('endDate')) aria-invalid="true" @endif>
                @error('endDate')<p class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>@enderror
            </div>
        </div>

        <div>
            <label class="label" for="sick-reason">{{ __('patients.sickReason') }}</label>
            <input class="input-field" id="sick-reason" name="reason" type="text" maxlength="200"
                   value="{{ old('reason', $editing?->reason ?? '') }}" @if ($errors->has('reason')) aria-invalid="true" @endif>
            @error('reason')<p class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>@enderror
        </div>

        <div>
            <label class="label" for="sick-note">{{ __('patients.sickNote') }}</label>
            <textarea class="input-field min-h-16" id="sick-note" name="note" rows="3" maxlength="2000"
                      @if ($errors->has('note')) aria-invalid="true" @endif>{{ old('note', $editing?->note ?? '') }}</textarea>
            @error('note')<p class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>@enderror
        </div>

        @error('patientId')
            <p role="alert" class="text-sm font-medium text-red-600">{{ $tv($message) }}</p>
        @enderror

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="btn-primary">{{ __('common.save') }}</button>
            @if ($editing)
                <a href="{{ route('patients.show', $patient->id) }}?tab=sick" class="btn-secondary">{{ __('common.cancel') }}</a>
            @endif
        </div>
    </form>

    {{-- List --}}
    @if ($rows->isEmpty())
        <div class="card p-5 text-sm text-slate-500">
            <p>{{ __('patients.sickEmpty') }}</p>
            <a href="{{ route('patients.show', $patient->id) }}?tab=sick" class="mt-2 inline-block text-xs font-medium text-orange-700 hover:underline">{{ __('patients.sickAddFirst') }}</a>
        </div>
    @else
        <div class="card overflow-hidden p-0">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('patients.sickStartDate') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('patients.sickEndDate') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('patients.sickReason') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('patients.sickRecordedBy') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        <tr class="{{ $sickEditId === $row->id ? 'opacity-50' : '' }}">
                            {{-- Legacy quirk: the first cell spans the start+end date columns. --}}
                            <td class="px-4 py-2 text-slate-700" colspan="2">{{ ClinicTime::formatDateRange($row->start_date, $row->end_date, $timezone) }}</td>
                            <td class="px-4 py-2 text-slate-700">
                                {{ $row->reason ?: '—' }}
                                @if ($row->note)
                                    <span class="mt-0.5 block text-xs text-slate-500" title="{{ $row->note }}">{{ $row->note }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-slate-500">{{ $row->recorded_by_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('patients.show', $patient->id) }}?tab=sick&edit={{ $row->id }}" class="text-xs font-medium text-orange-700 hover:underline">{{ __('common.edit') }}</a>
                                    <form method="POST" action="{{ route('patients.sick-leave.destroy', [$patient->id, $row->id]) }}"
                                          data-confirm="{{ __('patients.sickDeleteConfirm') }}">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium text-red-600 hover:underline">{{ __('common.delete') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
