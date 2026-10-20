@php
    /** @var array{count: int, skipped: int, staffCount: int, sample: list<array{staffName: string, dateKey: string, start: string, end: string}>}|null $preview */
    /** @var array<string, string> $errors */
@endphp

@if ($errors !== [])
    <div role="alert" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
        {{ __('common.validationError') }}
    </div>
@elseif ($preview !== null)
    <div class="mt-3 rounded-xl border border-brand-100 bg-brand-50 px-4 py-2 text-sm text-brand-800">
        {{ __t('hr.previewResult', ['count' => $preview['count'], 'skipped' => $preview['skipped']]) }}
    </div>

    @if ($preview['sample'] !== [])
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-brand-100 text-slate-500">
                    <tr>
                        <th class="py-1 pr-3">{{ __('hr.staffLabel') }}</th>
                        <th class="py-1 pr-3">{{ __('hr.date') }}</th>
                        <th class="py-1 pr-3">{{ __('hr.startTime') }}</th>
                        <th class="py-1 pr-3">{{ __('hr.endTime') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview['sample'] as $row)
                        <tr class="border-b border-brand-50 last:border-0">
                            <td class="py-1 pr-3">{{ $row['staffName'] }}</td>
                            <td class="py-1 pr-3">{{ $row['dateKey'] }}</td>
                            <td class="py-1 pr-3">{{ $row['start'] }}</td>
                            <td class="py-1 pr-3">{{ $row['end'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif
