@extends('layouts.app')

@section('title', __('sales.revenueTitle'))

@php
    use App\Support\Sales\Constants;
    use App\Support\Services\Categories;

    $presetKeys = [
        'today' => 'periodToday',
        'week' => 'periodWeek',
        'month' => 'periodMonth',
        'lastMonth' => 'periodLastMonth',
        'custom' => 'periodCustom',
    ];

    $presetLink = function (string $value) use ($preset, $custom): string {
        $params = ['period' => $value];
        if ($value === 'custom') {
            if (! empty($custom['from'])) $params['from'] = $custom['from'];
            if (! empty($custom['to'])) $params['to'] = $custom['to'];
        }
        return route('revenue.index').'?'.http_build_query($params);
    };

    $bucketLabel = function (array $bucket, string $kind): string {
        if ($kind === 'category' && Categories::isServiceCategory($bucket['key'])) {
            return __('services.'.Categories::labelKey($bucket['key']));
        }
        if ($kind === 'payment' && array_key_exists($bucket['key'], Constants::PAYMENT_LABEL_KEYS)) {
            return __('sales.'.Constants::paymentLabelKey($bucket['key']));
        }
        return ($bucket['label'] ?? '') !== '' ? $bucket['label'] : '—';
    };

    $changeText = $change === null
        ? '—'
        : (($change >= 0 ? '+' : '').$change.'%');
@endphp

@section('content')
    <div class="space-y-5">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('sales.revenueTitle') }}</h1>

        <div class="flex flex-wrap items-end gap-3">
            <div class="flex flex-wrap gap-2">
                @foreach ($presetKeys as $value => $labelKey)
                    <a href="{{ $presetLink($value) }}" class="{{ $value === $preset ? 'btn-primary' : 'btn-secondary' }}">
                        {{ __('sales.'.$labelKey) }}
                    </a>
                @endforeach
            </div>
            @if ($preset === 'custom')
                <form method="get" action="{{ route('revenue.index') }}" class="card grid grid-cols-1 gap-2 p-3 sm:grid-cols-2">
                    <input type="hidden" name="period" value="custom">
                    <div>
                        <label class="label text-xs" for="from">{{ __('sales.from') }}</label>
                        <input class="input-field text-sm" id="from" name="from" type="date" value="{{ $current['fromKey'] }}">
                    </div>
                    <div>
                        <label class="label text-xs" for="to">{{ __('sales.to') }}</label>
                        <input class="input-field text-sm" id="to" name="to" type="date" value="{{ $current['toKey'] }}">
                    </div>
                    <button type="submit" class="btn-secondary px-3 py-1.5 text-sm">{{ __('common.filter') }}</button>
                </form>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card space-y-1 p-5">
                <p class="text-xs uppercase text-slate-500">{{ __('sales.totalRevenue') }}</p>
                <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ format_money($report['totalRevenue'], $currency) }}</p>
                <p class="text-xs text-slate-500">{{ __t('sales.vsPrevious', ['change' => $changeText]) }}</p>
            </div>
            <div class="card space-y-1 p-5">
                <p class="text-xs uppercase text-slate-500">{{ __('sales.salesCount') }}</p>
                <p class="text-2xl font-semibold text-slate-900">{{ $report['salesCount'] }}</p>
            </div>
            <div class="card space-y-1 p-5">
                <p class="text-xs uppercase text-slate-500">{{ __('sales.averageSale') }}</p>
                <p class="text-2xl font-semibold text-slate-900 tabular-nums">{{ format_money($report['averageSale'], $currency) }}</p>
            </div>
            <div class="card space-y-1 p-5">
                <p class="text-xs uppercase text-slate-500">{{ __('sales.voidedTotal') }}</p>
                <p class="text-2xl font-semibold text-red-700 tabular-nums">{{ format_money($report['voidedTotal'], $currency) }}</p>
                <p class="text-xs text-slate-500">{{ __t('sales.voidedCount', ['count' => $report['voidedCount']]) }}</p>
            </div>
        </div>

        <div class="card space-y-3 p-5">
            <h2 class="text-sm font-semibold text-slate-800">{{ __('sales.dailyTrend') }}</h2>
            @if (count($trend) === 0)
                <p class="text-sm text-slate-500">{{ __('common.empty') }}</p>
            @else
                <ul class="space-y-1">
                    @foreach ($trend as $day)
                        <li class="flex items-center gap-3 text-xs">
                            <span class="w-24 shrink-0 text-slate-500">{{ $day['dateKey'] }}</span>
                            <span class="h-2 rounded bg-slate-800"
                                  style="width: {{ ((float) $day['total'] / (float) $trendMax) * 100 }}%; min-width: {{ $day['count'] > 0 ? '2px' : '0' }}"></span>
                            <span class="text-slate-600">{{ format_money($day['total'], $currency) }} ({{ $day['count'] }})</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            @foreach ([
                ['title' => __('sales.byService'), 'buckets' => $report['byService'], 'kind' => 'service'],
                ['title' => __('sales.byCategory'), 'buckets' => $report['byCategory'], 'kind' => 'category'],
                ['title' => __('sales.byDoctor'), 'buckets' => $report['byDoctor'], 'kind' => 'doctor'],
                ['title' => __('sales.byPaymentMethod'), 'buckets' => $report['byPaymentMethod'], 'kind' => 'payment'],
            ] as $section)
                <div class="card space-y-3 p-5">
                    <h2 class="text-sm font-semibold text-slate-800">{{ $section['title'] }}</h2>
                    @if (count($section['buckets']) === 0)
                        <p class="text-sm text-slate-500">{{ __('common.empty') }}</p>
                    @else
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-xs uppercase text-slate-500">
                                    <th class="py-2 pr-3 font-medium">{{ __('sales.name') }}</th>
                                    <th class="py-2 pr-3 text-right font-medium">{{ __('sales.salesCount') }}</th>
                                    <th class="py-2 text-right font-medium">{{ __('sales.total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($section['buckets'] as $bucket)
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <td class="py-2 pr-3 text-slate-700">{{ $bucketLabel($bucket, $section['kind']) }}</td>
                                        <td class="py-2 pr-3 text-right text-slate-600">{{ $bucket['count'] }}</td>
                                        <td class="py-2 text-right font-medium text-slate-900 tabular-nums">{{ format_money($bucket['total'], $currency) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endsection
