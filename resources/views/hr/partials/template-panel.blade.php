@php
    /** @var \Illuminate\Support\Collection $staffOptions */
    /** @var list<array<string, mixed>> $templates */
    use App\Support\Hr\Constants;

    $weekdayLabel = fn (int $weekday): string => __('hr.'.Constants::WEEKDAY_LABEL_KEYS[$weekday]);
@endphp

<section class="card space-y-4 p-5">
    <h2 class="text-sm font-semibold text-slate-800">{{ __('hr.templates') }}</h2>

    {{-- Weekly templates --}}
    <form method="POST" action="{{ route('schedule.templates.store') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
        @csrf
        <div>
            <label class="label text-xs" for="tpl-staffId">{{ __('hr.staffLabel') }}</label>
            <select class="input-field text-sm" id="tpl-staffId" name="staffId">
                <option value="">{{ __('hr.selectStaff') }}</option>
                @foreach ($staffOptions as $staff)
                    <option value="{{ $staff->id }}" @selected(old('staffId') === $staff->id)>{{ $staff->full_name }}</option>
                @endforeach
            </select>
            @error('staffId')<p class="mt-1 text-xs text-red-600">{{ field_error('staffId') }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="tpl-weekday">{{ __('hr.week') }}</label>
            <select class="input-field text-sm" id="tpl-weekday" name="weekday">
                @foreach (Constants::WEEKDAY_LABEL_KEYS as $index => $key)
                    <option value="{{ $index }}" @selected(old('weekday') === (string) $index)>{{ __('hr.'.$key) }}</option>
                @endforeach
            </select>
            @error('weekday')<p class="mt-1 text-xs text-red-600">{{ field_error('weekday') }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="tpl-startTime">{{ __('hr.startTime') }}</label>
            <input class="input-field text-sm" id="tpl-startTime" name="startTime" type="time" value="{{ old('startTime', '09:00') }}">
            @error('startTime')<p class="mt-1 text-xs text-red-600">{{ field_error('startTime') }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="tpl-endTime">{{ __('hr.endTime') }}</label>
            <input class="input-field text-sm" id="tpl-endTime" name="endTime" type="time" value="{{ old('endTime', '17:00') }}">
            @error('endTime')<p class="mt-1 text-xs text-red-600">{{ field_error('endTime') }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="tpl-validFrom">{{ __('hr.from') }}</label>
            <input class="input-field text-sm" id="tpl-validFrom" name="validFrom" type="date" value="{{ old('validFrom', $startKey) }}">
            @error('validFrom')<p class="mt-1 text-xs text-red-600">{{ field_error('validFrom') }}</p>@enderror
        </div>
        <div>
            <label class="label text-xs" for="tpl-validTo">{{ __('hr.to') }}</label>
            <input class="input-field text-sm" id="tpl-validTo" name="validTo" type="date" value="{{ old('validTo') }}">
            @error('validTo')<p class="mt-1 text-xs text-red-600">{{ field_error('validTo') }}</p>@enderror
        </div>
        <div class="sm:col-span-2 lg:col-span-6">
            <button type="submit" class="btn-primary">{{ __('common.create') }}</button>
        </div>
    </form>

    @if ($templates !== [])
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-brand-100 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="py-2 pr-3 font-medium">{{ __('hr.staffLabel') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('hr.week') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('hr.startTime') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('hr.endTime') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('hr.from') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('hr.to') }}</th>
                        <th class="py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($templates as $template)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="py-2 pr-3">{{ $template['staffName'] }}</td>
                            <td class="py-2 pr-3">{{ $weekdayLabel((int) $template['weekday']) }}</td>
                            <td class="py-2 pr-3">{{ $template['startTime'] }}</td>
                            <td class="py-2 pr-3">{{ $template['endTime'] }}</td>
                            <td class="py-2 pr-3">{{ \App\Support\Hr\Shifts::dateColumnToKey($template['validFrom']) }}</td>
                            <td class="py-2 pr-3">{{ $template['validTo'] === null ? '—' : \App\Support\Hr\Shifts::dateColumnToKey($template['validTo']) }}</td>
                            <td class="py-2 text-right">
                                <form method="POST" action="{{ route('schedule.templates.destroy', $template['id']) }}">
                                    @csrf
                                    <button type="submit" class="text-xs text-red-600 hover:underline">{{ __('common.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Generate from templates --}}
    <form method="POST" action="{{ route('schedule.templates.apply') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-4">
        @csrf
        <div>
            <label class="label text-xs" for="gen-staffId">{{ __('hr.allStaff') }}</label>
            <select class="input-field text-sm" id="gen-staffId" name="staffId">
                <option value="">{{ __('hr.allStaff') }}</option>
                @foreach ($staffOptions as $staff)
                    <option value="{{ $staff->id }}">{{ $staff->full_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label text-xs" for="gen-fromKey">{{ __('hr.from') }}</label>
            <input class="input-field text-sm" id="gen-fromKey" name="fromKey" type="date" value="{{ old('fromKey', $startKey) }}">
        </div>
        <div>
            <label class="label text-xs" for="gen-toKey">{{ __('hr.to') }}</label>
            <input class="input-field text-sm" id="gen-toKey" name="toKey" type="date" value="{{ old('toKey', \App\Support\ClinicTime::dateKeyAdd($startKey, 6)) }}">
        </div>
        <div class="flex items-end">
            <button type="submit" class="btn-secondary w-full">{{ __('hr.applyTemplate') }}</button>
        </div>
    </form>

    @if (session('templateResult'))
        <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">
            {{ __t('hr.previewResult', [
                'count' => session('templateResult')['count'],
                'skipped' => session('templateResult')['skipped'],
            ]) }}
        </p>
    @endif

    {{-- Copy last week --}}
    <form method="POST" action="{{ route('schedule.templates.copy') }}" class="flex items-end gap-3">
        @csrf
        <input type="hidden" name="weekStart" value="{{ $startKey }}">
        <div class="flex-1">
            <p class="text-xs text-slate-500">{{ __('hr.copyLastWeekHint') }}</p>
        </div>
        <button type="submit" class="btn-secondary">{{ __('hr.copyLastWeek') }}</button>
    </form>
</section>
