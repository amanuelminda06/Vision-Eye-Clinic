@php
    use App\Support\ClinicTime;
    use App\Support\Sales\Constants;

    $statusClass = fn (string $status): string => match ($status) {
        'CONFIRMED' => 'bg-emerald-50 text-emerald-700',
        'VOIDED' => 'bg-red-50 text-red-700',
        default => 'bg-amber-50 text-amber-700',
    };
@endphp

@if (count($sales['rows']) === 0)
    <div class="card p-5 text-sm text-slate-600">{{ __('sales.patientNoSales') }}</div>
@else
    <div class="card space-y-3 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="text-slate-600">{{ __t('sales.patientSalesCount', ['count' => $sales['count']]) }}</span>
            <span class="font-medium text-slate-900">{{ __t('sales.patientTotal', ['total' => format_money($sales['confirmedTotal'])]) }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs uppercase text-slate-500">
                        <th class="py-2 pr-3 font-medium">{{ __('sales.receiptNumber') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('sales.date') }}</th>
                        <th class="py-2 pr-3 font-medium">{{ __('sales.status') }}</th>
                        <th class="py-2 pr-3 text-right font-medium">{{ __('sales.total') }}</th>
                        <th class="py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sales['rows'] as $row)
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="py-2 pr-3 font-medium text-slate-900 tabular-nums">{{ $row['receiptNumber'] }}</td>
                            <td class="py-2 pr-3 text-slate-600">{{ ClinicTime::formatInTz($row['saleDate'], $timezone) }}</td>
                            <td class="py-2 pr-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($row['status']) }}">
                                    {{ __('sales.'.Constants::statusLabelKey($row['status'])) }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-right text-slate-800 tabular-nums">{{ format_money($row['total']) }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('sales.show', $row['id']) }}" class="text-slate-700 hover:underline">{{ __('sales.view') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
