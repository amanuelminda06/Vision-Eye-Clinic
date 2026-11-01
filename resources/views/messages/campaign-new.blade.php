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
            <h2 class="text-sm font-semibold text-slate-800">{{ __('messages.searchPatients') }}</h2>
            <input id="patientSearch" class="input-field text-sm" type="search" placeholder="{{ __('messages.searchPatientsHint') }}" autocomplete="off">
            <p class="text-xs text-slate-500">{{ __('messages.searchPatientsNote') }}</p>
            <div id="patientResults" class="mt-1 hidden max-h-72 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg"></div>

            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('messages.selectedPatients') }}</h3>
                <div id="selectedPatients" class="mt-2 flex flex-wrap gap-2"></div>
                <p id="noSelectedPatients" class="text-xs text-slate-500">{{ __('messages.noPatientSelected') }}</p>
            </div>
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
                        <option value="GROUP">Group</option>
                    </select>
                </div>
            </div>

            <div class="hidden">
                <label class="label text-xs" for="handPickedIds">{{ __('messages.selectedPatients') }}</label>
                <input type="hidden" id="handPickedIds" name="handPickedIds" value="{{ old('handPickedIds') }}">
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
            var endpoint = @json(route('messages.recipients'));
            var search = document.getElementById('patientSearch');
            var results = document.getElementById('patientResults');
            var picked = document.getElementById('handPickedIds');
            var chips = document.getElementById('selectedPatients');
            var emptyNote = document.getElementById('noSelectedPatients');

            if (!search || !results || !picked || !chips) return;

            var timer = null;
            var labels = {}; // patient id -> display name

            function selectedIds() {
                return picked.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            }

            function renderChips() {
                var current = selectedIds();
                chips.innerHTML = '';

                current.forEach(function (id) {
                    var chip = document.createElement('span');
                    chip.className = 'inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700';

                    var label = document.createElement('span');
                    label.textContent = labels[id] || id;

                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'text-base leading-none text-slate-400 hover:text-red-600';
                    remove.textContent = '×';
                    remove.setAttribute('aria-label', @json(__('messages.remove')));
                    remove.addEventListener('click', function () {
                        picked.value = selectedIds().filter(function (value) { return value !== id; }).join(',');
                        delete labels[id];
                        renderChips();
                    });

                    chip.appendChild(label);
                    chip.appendChild(remove);
                    chips.appendChild(chip);
                });

                emptyNote.classList.toggle('hidden', current.length > 0);
            }

            function addPatient(id, name) {
                var current = selectedIds();
                if (current.indexOf(id) === -1) {
                    current.push(id);
                    picked.value = current.join(',');
                }
                labels[id] = name;
                renderChips();
            }

            function hideResults() {
                results.classList.add('hidden');
                results.innerHTML = '';
            }

            search.addEventListener('input', function () {
                clearTimeout(timer);
                var q = search.value.trim();

                if (q.length < 2) {
                    hideResults();
                    return;
                }

                timer = setTimeout(function () {
                    fetch(endpoint + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var patients = (data && data.patients) ? data.patients : [];
                            results.innerHTML = '';

                            if (patients.length === 0) {
                                var none = document.createElement('p');
                                none.className = 'px-3 py-2 text-sm text-slate-500';
                                none.textContent = @json(__('messages.noPatientsFound'));
                                results.appendChild(none);
                                results.classList.remove('hidden');
                                return;
                            }

                            patients.forEach(function (p) {
                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'block w-full px-3 py-2 text-left text-sm hover:bg-slate-50';
                                btn.textContent = p.title + (p.subtitle ? ' — ' + p.subtitle : '');
                                btn.addEventListener('click', function () {
                                    addPatient(p.id, p.title);
                                    search.value = '';
                                    hideResults();
                                    search.focus();
                                });
                                results.appendChild(btn);
                            });

                            results.classList.remove('hidden');
                        })
                        .catch(hideResults);
                }, 200);
            });

            document.addEventListener('click', function (e) {
                if (!results.contains(e.target) && e.target !== search) {
                    hideResults();
                }
            });

            // Restoration after a validation redirect: keep the picked ids and
            // resolve their names so the chips are not raw ULIDs.
            var initial = selectedIds();
            if (initial.length > 0) {
                initial.forEach(function (id) { labels[id] = id; });
                renderChips();

                fetch(endpoint + '?ids=' + encodeURIComponent(initial.join(',')), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        (data && data.patients ? data.patients : []).forEach(function (p) {
                            labels[p.id] = p.title;
                        });
                        renderChips();
                    })
                    .catch(function () {});
            } else {
                renderChips();
            }

            var form = document.querySelector('form[method="POST"]');
            if (form) {
                var confirmTemplate = @json(__('messages.sendConfirm', ['count' => ':count']));
                var confirmFiltered = @json(__('messages.sendConfirmFiltered'));

                form.addEventListener('submit', function (e) {
                    var count = selectedIds().length;
                    var msg = count > 0
                        ? confirmTemplate.replace(':count', String(count))
                        : confirmFiltered;

                    if (!window.confirm(msg)) {
                        e.preventDefault();
                    }
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
