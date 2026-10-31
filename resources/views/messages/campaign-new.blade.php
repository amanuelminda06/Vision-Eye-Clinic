@extends('layouts.app')

@section('title', __('messages.newMessage'))

@php
    use App\Support\Messaging\Constants;
    $tv = fn (?string $key) => $key === null ? null : field_error($key);
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-xl font-semibold text-slate-900">{{ __('messages.newMessage') }}</h1>
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

        <section class="card space-y-3 p-4">
            <h2 class="text-sm font-semibold text-slate-800">Search patients</h2>
            <input id="patientSearch" class="input-field text-sm" type="search" placeholder="Search patients by name or phone" autocomplete="off">
            <div id="patientResults" class="mt-1 hidden rounded-lg border border-slate-200 bg-white shadow-lg"></div>
        </section>

        <form method="POST" action="{{ route('messages.campaigns.store') }}" class="card space-y-4 p-4">
            @csrf

            <div>
                <label class="label" for="title">Name</label>
                <input class="input-field" id="title" name="title" maxlength="120" value="{{ old('title') }}" required>
                @error('title')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div>
                <label class="label text-xs" for="templateKey">Template</label>
                <select class="input-field text-sm" id="templateKey" name="templateKey">
                    <option value="">Write your own</option>
                    @foreach ($templateKeys ?? Constants::TEMPLATE_KEYS as $tk)
                        <option value="{{ $tk }}">{{ __('messages.'.(Constants::TEMPLATE_LABEL_KEYS[$tk] ?? $tk)) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="label" for="body">Message</label>
                <textarea class="input-field" id="body" name="body" rows="4" maxlength="1000">{{ old('body') }}</textarea>
                @error('body')<p class="text-xs text-red-600">{{ $tv($message) }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="label text-xs" for="languageMode">Language mode</label>
                    <select class="input-field text-sm" id="languageMode" name="languageMode">
                        <option value="PATIENT" @selected(old('languageMode', 'PATIENT') === 'PATIENT')>Per patient</option>
                        <option value="FIXED" @selected(old('languageMode') === 'FIXED')>Fixed</option>
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="fixedLanguage">Message language</label>
                    <select class="input-field text-sm" id="fixedLanguage" name="fixedLanguage">
                        <option value="en" @selected(old('fixedLanguage', 'en') === 'en')>English</option>
                        <option value="am" @selected(old('fixedLanguage') === 'am')>Amharic</option>
                    </select>
                </div>
                <div>
                    <label class="label text-xs" for="deliveryMode">Delivery</label>
                    <select class="input-field text-sm" id="deliveryMode" name="deliveryMode">
                        <option value="INDIVIDUAL">Individual (1 person)</option>
                        <option value="INDIVIDUAL">Campaign (individual)</option>
                        <option value="GROUP">Group</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="label text-xs" for="handPickedIds">Patient IDs (comma separated)</label>
                <input class="input-field text-sm" id="handPickedIds" name="handPickedIds" value="{{ old('handPickedIds') }}">
                <p class="text-xs text-slate-500">Search above and click a patient to add their ID here.</p>
            </div>

            <details class="rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-medium text-slate-700">Advanced filter</summary>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label text-xs">Services used</label>
                        <select class="input-field text-sm" name="filter[serviceIds][]" multiple size="4">
                            @foreach (($services ?? collect()) as $svc)
                                <option value="{{ $svc->id }}" @selected(in_array($svc->id, (array) ($filter['serviceIds'] ?? []), true))>{{ $svc->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-slate-500">Hold Ctrl/Cmd to select multiple.</p>
                    </div>
                    <div>
                        <label class="label text-xs">Language</label>
                        <select class="input-field text-sm" name="filter[language]">
                            <option value="">Any</option>
                            <option value="en">English</option>
                            <option value="am">Amharic</option>
                        </select>
                    </div>
                    <div>
                        <label class="label text-xs">Last visit from</label>
                        <input class="input-field text-sm" type="date" name="filter[lastVisitFrom]" value="{{ $filter['lastVisitFrom'] ?? '' }}">
                    </div>
                    <div>
                        <label class="label text-xs">Last visit to</label>
                        <input class="input-field text-sm" type="date" name="filter[lastVisitTo]" value="{{ $filter['lastVisitTo'] ?? '' }}">
                    </div>
                </div>
            </details>

            <button type="submit" class="btn-primary">Send</button>
        </form>
    </div>

    @push('scripts')
    <script>
        (function () {
            var search = document.getElementById('patientSearch');
            var results = document.getElementById('patientResults');
            var picked = document.getElementById('handPickedIds');
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
                                        var ids = picked.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                                        if (ids.indexOf(p.id) === -1) ids.push(p.id);
                                        picked.value = ids.join(',');
                                        search.value = p.title;
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

            var form = document.querySelector('form[method="POST"]');
            if (form) {
                form.addEventListener('submit', function (e) {
                    var body = document.getElementById('body');
                    var bodyVal = body ? body.value.trim() : '';
                    var ids = document.getElementById('handPickedIds').value.trim();
                    var msg = 'Send this message?\n\nRecipients: ' + (ids || 'filtered') + '\n\n' + bodyVal;
                    if (!window.confirm(msg)) e.preventDefault();
                });
            }

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
        })();
    </script>
    @endpush
@endsection
