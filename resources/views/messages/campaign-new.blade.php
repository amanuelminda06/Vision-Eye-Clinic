@extends('layouts.app')

@section('title', __('messages.campaignsForbidden') === 'messages.campaignsForbidden' ? __('messages.newCampaign') : __('messages.newCampaign'))

@php
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.newCampaign') }}</h1>
            <a href="{{ route('messages.campaigns') }}" class="btn-secondary">{{ __('messages.backToCampaigns') }}</a>
        </div>

        @if (session('actionError'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('messages.'.session('actionError')) }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ __('common.validationError') }}
            </div>
        @endif

        {{-- Audience filter (GET) --}}
    <section class="card space-y-3 p-4">
        <h2 class="text-sm font-semibold text-slate-800">{{ __('messages.filterTitle') }}</h2>
        <form method="GET" action="{{ route('messages.campaigns.new') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="label text-xs" for="f_tags">{{ __('messages.filterTags') }}</label>
                <input class="input-field text-sm" id="f_tags" name="filter[tags]" value="{{ implode(',', $filter['tags'] ?? []) }}">
            </div>
            <div>
                <label class="label text-xs" for="f_tagMode">{{ __('messages.filterTagMode') }}</label>
                <select class="input-field text-sm" id="f_tagMode" name="filter[tagMode]">
                    <option value="ANY" @selected(($filter['tagMode'] ?? 'ANY') === 'ANY')>ANY</option>
                    <option value="ALL" @selected(($filter['tagMode'] ?? '') === 'ALL')>ALL</option>
                </select>
            </div>
            <div>
                <label class="label text-xs" for="f_language">{{ __('messages.filterLanguage') }}</label>
                <select class="input-field text-sm" id="f_language" name="filter[language]">
                    <option value="" @selected(($filter['language'] ?? '') === '')>Any</option>
                    <option value="en" @selected(($filter['language'] ?? '') === 'en')>English</option>
                    <option value="am" @selected(($filter['language'] ?? '') === 'am')>Amharic</option>
                </select>
            </div>
            <div>
                <label class="label text-xs" for="f_upcoming">{{ __('messages.filterUpcoming') }}</label>
                <select class="input-field text-sm" id="f_upcoming" name="filter[upcoming]">
                    <option value="ANY" @selected(($filter['upcoming'] ?? 'ANY') === 'ANY')>Any</option>
                    <option value="HAS" @selected(($filter['upcoming'] ?? '') === 'HAS')>Has upcoming</option>
                    <option value="NONE" @selected(($filter['upcoming'] ?? '') === 'NONE')>No upcoming</option>
                </select>
            </div>
            <div>
                <label class="label text-xs" for="f_preset">{{ __('messages.filterPresetRange') }}</label>
                <select class="input-field text-sm" id="f_preset" name="filter[presetRange]">
                    <option value="" @selected(($filter['presetRange'] ?? '') === '')>Custom</option>
                    <option value="last30" @selected(($filter['presetRange'] ?? '') === 'last30')>Last 30 days</option>
                    <option value="last90" @selected(($filter['presetRange'] ?? '') === 'last90')>Last 90 days</option>
                    <option value="last180" @selected(($filter['presetRange'] ?? '') === 'last180')>Last 180 days</option>
                    <option value="thisYear" @selected(($filter['presetRange'] ?? '') === 'thisYear')>This year</option>
                </select>
            </div>
            <div>
                <label class="label text-xs" for="f_lvfrom">{{ __('messages.filterLastVisitFrom') }}</label>
                <input class="input-field text-sm" id="f_lvfrom" type="date" name="filter[lastVisitFrom]" value="{{ $filter['lastVisitFrom'] ?? '' }}">
            </div>
            <div>
                <label class="label text-xs" for="f_lvto">{{ __('messages.filterLastVisitTo') }}</label>
                <input class="input-field text-sm" id="f_lvto" type="date" name="filter[lastVisitTo]" value="{{ $filter['lastVisitTo'] ?? '' }}">
            </div>
            <div>
                <label class="label text-xs" for="f_handpicked">{{ __('messages.filterHandPicked') }}</label>
                <input class="input-field text-sm" id="f_handpicked" name="filter[handPickedIds]" value="{{ implode(',', $filter['handPickedIds'] ?? []) }}">
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <button type="submit" class="btn-secondary text-sm">{{ __('common.filter') ?? 'Apply filter' }}</button>
            </div>
        </form>
    </section>

    {{-- Audience preview --}}
        <section class="card space-y-3 p-4">
            <h2 class="text-sm font-semibold text-slate-800">{{ __('messages.preview') }}</h2>
            <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('common.showing', ['from' => 1, 'to' => 1, 'total' => $preview['matched']]) }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $preview['matched'] }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('common.active') }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $preview['eligible'] }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('common.inactive') }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $preview['excluded']['inactive'] + $preview['excluded']['doNotMessage'] + $preview['excluded']['invalidPhone'] }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-slate-500">{{ __('messages.campaignsTitle') }}</p>
                    <p class="text-lg font-semibold tabular-nums">{{ $preview['cap'] }}</p>
                </div>
            </div>

            @if ($preview['overCap'])
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('messages.campaignOverCap') }}</p>
            @endif

            @if ($preview['groupBlockReasons'] !== [])
                <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ __('messages.groupNotAllowed') }}</p>
            @endif
        </section>

        {{-- Patient picker --}}
    <section class="card space-y-3 p-4">
        <h2 class="text-sm font-semibold text-slate-800">Patients</h2>
        <div class="relative">
            <input id="patientSearch" class="input-field text-sm" type="search" placeholder="Search patients by name or phone" autocomplete="off">
            <div id="patientResults" class="absolute z-10 mt-1 hidden w-full rounded-lg border border-slate-200 bg-white shadow-lg"></div>
        </div>
        <div id="patientChips" class="flex flex-wrap gap-2"></div>
        <p class="text-xs text-slate-500"><span id="patientCount">0</span> selected</p>
    </section>

    {{-- The campaign form --}}
        <form method="POST" action="{{ route('messages.campaigns.store') }}" class="card space-y-4 p-4">
            @csrf
            <input type="hidden" name="filter" id="filterJson" value="{{ json_encode($filter) }}">
            <input type="hidden" name="handPickedIds" id="handPickedIds" value="{{ implode(',', $filter['handPickedIds'] ?? []) }}">

            <div>
                <label class="label" for="title">{{ __('common.name') }}</label>
                <input class="input-field" id="title" name="title" maxlength="120" value="{{ old('title') }}">
                @error('title')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div>
                <label class="label text-xs" for="templateKey">{{ __('messages.template') ?? 'Template (optional)' }}</label>
                <select class="input-field text-sm" id="templateKey" name="templateKey">
                    <option value="">{{ __('messages.customMessage') }}</option>
                    @foreach ($templateKeys ?? \App\Support\Messaging\Constants::TEMPLATE_KEYS as $tk)
                        <option value="{{ $tk }}">{{ __('messages.'.(\App\Support\Messaging\Constants::TEMPLATE_LABEL_KEYS[$tk] ?? $tk)) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="body">{{ __('messages.customMessage') }}</label>
                <textarea class="input-field" id="body" name="body" rows="4" maxlength="1000">{{ old('body') }}</textarea>
                @error('body')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label text-xs" for="languageMode">{{ __('messages.languageMode') }}</label>
                    <select class="input-field text-sm" id="languageMode" name="languageMode">
                        <option value="PATIENT" @selected(old('languageMode', 'PATIENT') === 'PATIENT')>{{ __('messages.languagePatient') }}</option>
                        <option value="FIXED" @selected(old('languageMode') === 'FIXED')>{{ __('messages.languageFixed') }}</option>
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="fixedLanguage">{{ __('messages.messageLanguage') }}</label>
                    <select class="input-field text-sm" id="fixedLanguage" name="fixedLanguage">
                        <option value="en" @selected(old('fixedLanguage', 'en') === 'en')>English</option>
                        <option value="am" @selected(old('fixedLanguage') === 'am')>አማርኛ</option>
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="deliveryMode">{{ __('messages.deliveryIndividualShort') }}</label>
                    <select class="input-field text-sm" id="deliveryMode" name="deliveryMode">
                        <option value="INDIVIDUAL" @selected(old('deliveryMode', 'INDIVIDUAL') === 'INDIVIDUAL')>{{ __('messages.deliveryIndividualShort') }}</option>
                        <option value="GROUP" @selected(old('deliveryMode') === 'GROUP')>{{ __('messages.deliveryGroupShort') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="label text-xs" for="groupBatchSize">{{ __('messages.batchSize', ['size' => 15]) }}</label>
                <input class="input-field w-32" id="groupBatchSize" name="groupBatchSize" type="number" min="1" max="100" value="{{ old('groupBatchSize', 15) }}">
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="acknowledgedGroupWarning" value="true" @checked(old('acknowledgedGroupWarning') === 'true')>
                <span>{{ __('messages.groupAckText') }}</span>
            </label>

            <button type="submit" class="btn-primary">{{ __('messages.send') }}</button>
        </form>
    </div>
@push('scripts')
<script>
    (function () {
        var select = document.getElementById('templateKey');
        var body = document.getElementById('body');
        if (select && body) {
            var bodies = @json($bodies ?? []);
            select.addEventListener('change', function () {
                var key = select.value;
                if (!key) return;
                var en = bodies[key + ':en'] || '';
                var am = bodies[key + ':am'] || '';
                body.value = en !== '' ? en : am;
            });
        }

        // Patient picker
        var search = document.getElementById('patientSearch');
        var results = document.getElementById('patientResults');
        var chips = document.getElementById('patientChips');
        var countEl = document.getElementById('patientCount');
        var filterInput = document.getElementById('filterJson');
        var pickedInput = document.getElementById('handPickedIds');
        var selected = {};

        function syncFilter() {
            try {
                var f = JSON.parse(filterInput.value || '{}');
                f.handPickedIds = Object.keys(selected);
                filterInput.value = JSON.stringify(f);
            } catch (e) {}
            if (pickedInput) {
                pickedInput.value = Object.keys(selected).join(',');
            }
            countEl.textContent = Object.keys(selected).length;
        }

        function addPatient(id, title) {
            if (selected[id]) return;
            selected[id] = title;
            var chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1 rounded-full bg-brand-100 px-3 py-1 text-xs font-medium text-brand-800';
            chip.textContent = title;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ml-1 font-bold';
            btn.textContent = '×';
            btn.addEventListener('click', function () {
                delete selected[id];
                chip.remove();
                syncFilter();
            });
            chip.appendChild(btn);
            chips.appendChild(chip);
            syncFilter();
        }

        if (search && results) {
            var timer = null;
            search.addEventListener('input', function () {
                clearTimeout(timer);
                var q = search.value.trim();
                if (q.length < 3) { results.classList.add('hidden'); results.innerHTML = ''; return; }
                timer = setTimeout(function () {
                    fetch('/api/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var patients = (data && data.patients) ? data.patients : [];
                            results.innerHTML = '';
                            if (patients.length === 0) { results.classList.add('hidden'); return; }
                            patients.forEach(function (p) {
                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'block w-full px-3 py-2 text-left text-sm hover:bg-slate-50';
                                btn.textContent = p.title + (p.subtitle ? ' — ' + p.subtitle : '');
                                btn.addEventListener('click', function () {
                                    addPatient(p.id, p.title);
                                    search.value = '';
                                    results.classList.add('hidden');
                                    results.innerHTML = '';
                                });
                                results.appendChild(btn);
                            });
                            results.classList.remove('hidden');
                        })
                        .catch(function () { results.classList.add('hidden'); });
                }, 250);
            });
            document.addEventListener('click', function (e) {
                if (!results.contains(e.target) && e.target !== search) {
                    results.classList.add('hidden');
                }
            });
        }

        // Final confirmation on send
        var form = document.querySelector('form[method="POST"]');
        if (form) {
            form.addEventListener('submit', function (e) {
                var bodyVal = (body && body.value) ? body.value.trim() : '';
                var count = Object.keys(selected).length;
                if (count === 0) {
                    // allow filter-based sends (no hand-picked)
                }
                var msg = 'Send this message to ' + (count > 0 ? count : 'the filtered') + ' patient(s)?\n\n' + bodyVal;
                if (!window.confirm(msg)) {
                    e.preventDefault();
                }
            });
        }
    })();
</script>
@endpush

@endsection
