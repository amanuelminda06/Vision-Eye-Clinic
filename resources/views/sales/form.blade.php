@extends('layouts.app')

@section('title', __('sales.newSale'))

@php
    use App\Support\Sales\Constants;

    $serviceOptions = $services->map(fn ($s): array => [
        'id' => $s->id,
        'name' => $s->name,
        'nameEn' => $s->name_en,
        'nameAm' => $s->name_am,
        'currentPrice' => \App\Support\Money::toDecimalString($s->current_price),
    ])->values();

    $locale = app()->getLocale();
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
    $prefillLine = null;
    if ($prefillServiceId !== '') {
        $found = $services->firstWhere('id', $prefillServiceId);
        if ($found !== null) {
            $prefillLine = [
                'serviceId' => $found->id,
                'name' => $locale === 'am' && $found->name_am ? $found->name_am : $found->name,
                'unitPrice' => \App\Support\Money::toDecimalString($found->current_price),
                'qty' => 1,
            ];
        }
    }
@endphp

@section('content')
    <div class="mx-auto max-w-5xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('sales.newSale') }}</h1>
            <a href="{{ route('sales.index') }}" class="btn-secondary">{{ __('common.back') }}</a>
        </div>

        <form id="sale-form" method="POST" action="{{ route('sales.store') }}" class="grid gap-5 lg:grid-cols-3">
            @csrf
            <input type="hidden" name="patientId" id="patientId" value="{{ old('patientId', '') }}">
            <input type="hidden" name="walkIn" id="walkIn" value="false">
            <input type="hidden" name="doctorId" id="doctorId" value="{{ old('doctorId', '') }}">
            <input type="hidden" name="lines" id="lines" value="">

            <div class="space-y-5 lg:col-span-2">
                {{-- Patient / walk-in --}}
                <section class="card space-y-3 p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-800">{{ __('sales.patient') }}</h2>
                        <button type="button" id="walkin-toggle" class="btn-secondary">{{ __('sales.walkIn') }}</button>
                    </div>

                    <p id="walkin-hint" class="hidden text-sm text-slate-600">{{ __('sales.walkInHint') }}</p>

                    <div id="patient-picked" class="hidden items-center justify-between rounded-lg border border-slate-200 px-3 py-2">
                        <div>
                            <div id="patient-picked-name" class="text-sm font-medium text-slate-900"></div>
                            <div id="patient-picked-phone" class="text-xs text-slate-500"></div>
                        </div>
                        <button type="button" id="patient-clear" class="btn-secondary">{{ __('common.clear') }}</button>
                    </div>

                    <div id="patient-search-wrap" class="relative">
                        <input class="input-field" id="patient-query" type="search" autocomplete="off"
                               placeholder="{{ __('sales.patientSearchPlaceholder') }}"
                               @if ($errors->has('patientId')) aria-invalid="true" @endif>
                        <ul id="patient-results" class="absolute z-10 mt-1 hidden w-full rounded-lg border border-slate-200 bg-white shadow-lg"></ul>
                        @error('patientId')<p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                </section>

                {{-- Services --}}
                <section class="card space-y-3 p-5">
                    <h2 class="text-sm font-semibold text-slate-800">{{ __('sales.services') }}</h2>
                    <div class="relative">
                        <input class="input-field" id="service-query" type="text" autocomplete="off"
                               placeholder="{{ __('sales.serviceSearchPlaceholder') }}">
                        <ul id="service-results" class="absolute z-10 mt-1 hidden w-full rounded-lg border border-slate-200 bg-white shadow-lg"></ul>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="py-2 pr-3 font-medium">{{ __('sales.service') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('sales.unitPrice') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('sales.qty') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('sales.lineTotal') }}</th>
                                    <th class="py-2 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody id="lines-body">
                                <tr id="lines-empty"><td colspan="5" class="py-6 text-center text-slate-500">{{ __('sales.noLines') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    @error('lines')<p role="alert" class="text-xs font-medium text-red-600">{{ $tv($message) }}</p>@enderror
                </section>
            </div>

            <aside class="space-y-5">
                <section class="card space-y-3 p-5">
                    <div>
                        <label class="label" for="doctor-select">{{ __('sales.doctor') }}</label>
                        <select class="input-field" id="doctor-select">
                            <option value="">{{ __('sales.noDoctor') }}</option>
                            @foreach ($doctors as $doctor)
                                <option value="{{ $doctor->id }}" @selected(old('doctorId') === $doctor->id)>{{ $doctor->full_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="paymentMethod">{{ __('sales.paymentMethod') }}</label>
                        <select class="input-field" id="paymentMethod" name="paymentMethod">
                            @foreach (Constants::PAYMENT_METHODS as $method)
                                <option value="{{ $method }}" @selected(old('paymentMethod', 'CASH') === $method)>
                                    {{ __('sales.'.Constants::paymentLabelKey($method)) }}
                                </option>
                            @endforeach
                        </select>
                        @error('paymentMethod')<p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ $tv($message) }}</p>@enderror
                    </div>
                </section>

                <section class="card space-y-3 p-5">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-600">{{ __('sales.total') }}</span>
                        <span id="sale-total" class="text-lg font-semibold text-slate-900 tabular-nums">0.00</span>
                    </div>
                    <button type="submit" class="btn-primary w-full" id="sale-submit">{{ __('sales.confirmSale') }}</button>
                    <p class="text-xs text-slate-500">{{ __('sales.totalsHint') }}</p>
                </section>
            </aside>
        </form>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var services = @json($serviceOptions);
    var prefill = @json($prefillLine);
    var maxQty = {{ \App\Support\Sales\Constants::MAX_QTY }};
    var locale = @json($locale);
    var lines = prefill ? [prefill] : [];

    var els = {
        patientId: document.getElementById('patientId'),
        walkIn: document.getElementById('walkIn'),
        doctorId: document.getElementById('doctorId'),
        lines: document.getElementById('lines'),
        walkinToggle: document.getElementById('walkin-toggle'),
        walkinHint: document.getElementById('walkin-hint'),
        pickedWrap: document.getElementById('patient-picked'),
        pickedName: document.getElementById('patient-picked-name'),
        pickedPhone: document.getElementById('patient-picked-phone'),
        patientClear: document.getElementById('patient-clear'),
        patientQuery: document.getElementById('patient-query'),
        patientResults: document.getElementById('patient-results'),
        serviceQuery: document.getElementById('service-query'),
        serviceResults: document.getElementById('service-results'),
        linesBody: document.getElementById('lines-body'),
        linesEmpty: document.getElementById('lines-empty'),
        total: document.getElementById('sale-total'),
        doctorSelect: document.getElementById('doctor-select'),
        submit: document.getElementById('sale-submit')
    };

    function money(v) { return (Math.round(Number(v) * 100) / 100).toFixed(2); }
    function serviceName(s) { return locale === 'am' && s.nameAm ? s.nameAm : (s.nameEn || s.name); }

    function renderLines() {
        els.linesBody.querySelectorAll('tr:not(#lines-empty)').forEach(function (r) { r.remove(); });
        if (lines.length === 0) {
            els.linesEmpty.classList.remove('hidden');
        } else {
            els.linesEmpty.classList.add('hidden');
            lines.forEach(function (line) {
                var tr = document.createElement('tr');
                tr.className = 'border-b border-slate-100 last:border-0';
                tr.innerHTML =
                    '<td class="py-2 pr-3 font-medium text-slate-900"></td>' +
                    '<td class="py-2 pr-3 text-slate-600">' + money(line.unitPrice) + '</td>' +
                    '<td class="py-2 pr-3"><input class="input-field w-20" type="number" min="1" max="' + maxQty + '" value="' + line.qty + '"></td>' +
                    '<td class="py-2 pr-3 font-medium text-slate-900">' + money(line.unitPrice * line.qty) + '</td>' +
                    '<td class="py-2 text-right"><button type="button" class="text-sm text-red-600 hover:underline">{{ __('common.delete') }}</button></td>';
                tr.querySelector('td').textContent = line.name;
                tr.querySelector('input').addEventListener('change', function (e) {
                    line.qty = Math.max(1, Math.min(maxQty, Number(e.target.value) || 1));
                    renderLines();
                });
                tr.querySelector('button').addEventListener('click', function () {
                    lines = lines.filter(function (l) { return l.serviceId !== line.serviceId; });
                    renderLines();
                });
                els.linesBody.appendChild(tr);
            });
        }
        var total = lines.reduce(function (sum, l) { return sum + Number(l.unitPrice) * l.qty; }, 0);
        els.total.textContent = money(total);
        els.lines.value = JSON.stringify(lines.map(function (l) { return { serviceId: l.serviceId, qty: l.qty }; }));
        els.submit.disabled = lines.length === 0;
    }

    function addService(service) {
        var existing = lines.find(function (l) { return l.serviceId === service.id; });
        if (existing) {
            existing.qty = Math.min(maxQty, existing.qty + 1);
        } else {
            lines.push({ serviceId: service.id, name: serviceName(service), unitPrice: service.currentPrice, qty: 1 });
        }
        els.serviceQuery.value = '';
        els.serviceResults.classList.add('hidden');
        renderLines();
    }

    els.serviceQuery.addEventListener('input', function () {
        var q = els.serviceQuery.value.trim().toLowerCase();
        els.serviceResults.innerHTML = '';
        if (!q) { els.serviceResults.classList.add('hidden'); return; }
        var matches = services.filter(function (s) {
            return (s.nameEn || '').toLowerCase().indexOf(q) !== -1 ||
                (s.name || '').toLowerCase().indexOf(q) !== -1 ||
                (s.nameAm || '').toLowerCase().indexOf(q) !== -1;
        }).slice(0, 6);
        matches.forEach(function (s) {
            var li = document.createElement('li');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50';
            btn.innerHTML = '<span class="font-medium text-slate-900"></span><span class="text-xs text-slate-500">' + money(s.currentPrice) + '</span>';
            btn.querySelector('span').textContent = s.nameEn || s.name;
            btn.addEventListener('click', function () { addService(s); });
            li.appendChild(btn);
            els.serviceResults.appendChild(li);
        });
        els.serviceResults.classList.toggle('hidden', matches.length === 0);
    });

    els.serviceQuery.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); var first = els.serviceResults.querySelector('button'); if (first) first.click(); }
    });

    var searchTimer = null;
    els.patientQuery.addEventListener('input', function () {
        els.patientId.value = '';
        els.patientResults.innerHTML = '';
        if (searchTimer) clearTimeout(searchTimer);
        var q = els.patientQuery.value.trim();
        if (q.length < 2) { els.patientResults.classList.add('hidden'); return; }
        searchTimer = setTimeout(function () {
            fetch('/api/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    (data.patients || []).forEach(function (p) {
                        var li = document.createElement('li');
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'block w-full px-3 py-2 text-left text-sm hover:bg-slate-50';
                        btn.innerHTML = '<span class="font-medium text-slate-900"></span><span class="ml-2 text-xs text-slate-500"></span>';
                        btn.querySelectorAll('span')[0].textContent = p.title;
                        btn.querySelectorAll('span')[1].textContent = p.subtitle;
                        btn.addEventListener('click', function () { pickPatient(p); });
                        li.appendChild(btn);
                        els.patientResults.appendChild(li);
                    });
                    els.patientResults.classList.toggle('hidden', (data.patients || []).length === 0);
                });
        }, 250);
    });

    function pickPatient(p) {
        els.patientId.value = p.id;
        els.patientQuery.value = p.title;
        els.patientResults.classList.add('hidden');
        els.pickedName.textContent = p.title;
        els.pickedPhone.textContent = p.subtitle;
        els.pickedWrap.classList.remove('hidden');
        els.pickedWrap.classList.add('flex');
        els.patientSearchWrap = document.getElementById('patient-search-wrap');
        els.patientSearchWrap.classList.add('hidden');
    }

    els.patientClear.addEventListener('click', function () {
        els.patientId.value = '';
        els.patientQuery.value = '';
        document.getElementById('patient-search-wrap').classList.remove('hidden');
        els.pickedWrap.classList.add('hidden');
        els.pickedWrap.classList.remove('flex');
    });

    els.walkinToggle.addEventListener('click', function () {
        var on = els.walkIn.value !== 'true';
        els.walkIn.value = on ? 'true' : 'false';
        els.walkinHint.classList.toggle('hidden', !on);
        els.walkinToggle.className = on ? 'btn-primary' : 'btn-secondary';
        if (on) {
            els.patientId.value = '';
            els.patientQuery.value = '';
            els.patientResults.classList.add('hidden');
            els.pickedWrap.classList.add('hidden');
            els.pickedWrap.classList.remove('flex');
            document.getElementById('patient-search-wrap').classList.add('hidden');
        } else {
            document.getElementById('patient-search-wrap').classList.remove('hidden');
        }
    });

    els.doctorSelect.addEventListener('change', function () { els.doctorId.value = els.doctorSelect.value; });

    renderLines();
})();
</script>
@endpush
