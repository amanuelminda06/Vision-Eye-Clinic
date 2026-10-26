@php
    /** @var \Illuminate\Support\Collection $staffOptions */
    $selectedStaff = old('staffId', '');
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
@endphp

<section class="card space-y-4 p-5">
    <h2 class="text-sm font-semibold text-slate-800">{{ __('hr.addShift') }}</h2>

    <form method="POST" action="{{ route('schedule.shifts.store') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @csrf
        <div>
            <label class="label text-xs" for="shift-staffId">{{ __('hr.staffLabel') }}</label>
            <select class="input-field text-sm" id="shift-staffId" name="staffId">
                <option value="">{{ __('hr.selectStaff') }}</option>
                @foreach ($staffOptions as $staff)
                    <option value="{{ $staff->id }}" @selected($selectedStaff === $staff->id)>{{ $staff->full_name }}</option>
                @endforeach
            </select>
            @error('staffId')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="shift-dateKey">{{ __('hr.date') }}</label>
            <input class="input-field text-sm" id="shift-dateKey" name="dateKey" type="date" value="{{ old('dateKey', $startKey) }}">
            @error('dateKey')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="shift-startTime">{{ __('hr.startTime') }}</label>
            <input class="input-field text-sm" id="shift-startTime" name="startTime" type="time" value="{{ old('startTime', '09:00') }}">
            @error('startTime')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="shift-endTime">{{ __('hr.endTime') }}</label>
            <input class="input-field text-sm" id="shift-endTime" name="endTime" type="time" value="{{ old('endTime', '17:00') }}">
            @error('endTime')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="shift-note">{{ __('hr.note') }}</label>
            <input class="input-field text-sm" id="shift-note" name="note" maxlength="200" value="{{ old('note') }}">
            @error('note')<p class="mt-1 text-xs text-red-600">{{ $tv($message) }}</p>@enderror
        </div>
        <div class="sm:col-span-2 lg:col-span-5">
            <p class="text-xs text-slate-500">{{ __('hr.overnightHint') }}</p>
            <button type="submit" class="btn-primary mt-2">{{ __('hr.addShift') }}</button>
        </div>
    </form>
</section>
