@extends('layouts.app')

@section('title', $patient->full_name)

@php
    use App\Support\ClinicTime;
    use App\Support\Patients\PatientTabs;
    use App\Support\Patients\PatientValidation;
    use App\Support\Phone;

    $tabs = [
        'overview' => 'tabOverview',
        'sales' => 'tabSales',
        'appointments' => 'tabAppointments',
        'messages' => 'tabMessages',
        'sick' => 'tabSick',
    ];

    $sexLabel = match ($patient->sex) {
        'MALE' => __('patients.sexMale'),
        'FEMALE' => __('patients.sexFemale'),
        'OTHER' => __('patients.sexOther'),
        default => '—',
    };

    $currentTags = $patient->tags->pluck('name')->all();
    $today = PatientValidation::todayIso();
    $sickEditId = $editing?->id;
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-semibold text-slate-900">{{ $patient->full_name }}</h1>
                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $patient->active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                    {{ $patient->active ? __('common.active') : __('common.inactive') }}
                </span>
                @if ($patient->do_not_message)
                    <span class="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{{ __('patients.dnmYes') }}</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('patients.index') }}" class="btn-secondary">{{ __('patients.backToList') }}</a>
                <a href="{{ route('patients.edit', $patient->id) }}" class="btn-primary">{{ __('common.edit') }}</a>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                {{-- Phone + demographics --}}
                <div class="card space-y-3 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <a href="tel:{{ $patient->phone_e164 }}" class="text-lg font-medium text-slate-900 hover:underline">{{ Phone::display($patient->phone_e164) }}</a>
                        <span class="text-xs text-slate-500">{{ __('patients.tapToCall') }}</span>
                    </div>
                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-xs uppercase text-slate-500">{{ __('patients.sex') }}</dt>
                            <dd class="text-slate-800">{{ $sexLabel }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-slate-500">{{ __('patients.birthDate') }}</dt>
                            <dd class="text-slate-800">{{ $patient->birth_date?->format('Y-m-d') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-slate-500">{{ __('patients.idNo') }}</dt>
                            <dd class="text-slate-800">{{ $patient->id_no ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-slate-500">{{ __('common.language') }}</dt>
                            <dd class="text-slate-800">{{ $patient->preferred_language === 'am' ? __('patients.langAm') : __('patients.langEn') }}</dd>
                        </div>
                    </dl>
                    <div class="text-xs text-slate-500">
                        <p>{{ __t('patients.createdMeta', ['name' => $patient->created_by_name ?? '—', 'date' => ClinicTime::formatInTz($patient->created_at, $timezone)]) }}</p>
                        <p>{{ __t('patients.updatedMeta', ['date' => ClinicTime::formatInTz($patient->updated_at, $timezone)]) }}</p>
                    </div>
                </div>

                {{-- ID photo capture --}}
                <form method="POST" action="{{ route('patients.photo', $patient->id) }}" enctype="multipart/form-data" class="card space-y-3 p-4">
                    @csrf
                    <div class="flex items-center gap-3">
                        @if ($patient->id_photo_url)
                            <img src="{{ $patient->id_photo_url }}" alt="" data-id-photo-thumb class="h-16 w-16 rounded-lg border border-slate-200 object-cover">
                        @else
                            <div data-id-photo-thumb class="h-16 w-16 rounded-lg border border-dashed border-slate-300 bg-slate-50"></div>
                        @endif
                        <div class="flex flex-col gap-2">
                            <input id="idPhoto" name="idPhoto" type="file" accept="image/png,image/jpeg,image/webp" capture="environment"
                                   class="text-xs text-slate-600 file:mr-2 file:rounded-md file:border-0 file:bg-orange-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-orange-700 hover:file:bg-orange-100">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="btn-secondary text-xs" data-id-photo-upload>{{ __('patients.idPhotoUpload') }}</button>
                                <button type="button" class="btn-secondary text-xs" data-id-photo-capture>{{ __('patients.idPhotoCapture') }}</button>
                                @if ($patient->id_photo_url)
                                    <button type="button" class="btn-secondary text-xs" data-id-photo-view>{{ __('patients.idPhotoView') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ __('patients.idPhotoHint') }}</p>
                    @error('idPhoto')
                        <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ field_error($message) }}</p>
                    @enderror
                    <button type="submit" class="btn-primary text-xs">{{ __('common.save') }}</button>
                </form>

                {{-- Tabs --}}
                <div class="border-b border-slate-200">
                    <nav class="-mb-px flex flex-wrap">
                        @foreach ($tabs as $id => $key)
                            <a href="{{ route('patients.show', $patient->id) }}?tab={{ $id }}"
                               class="border-b-2 px-3 py-2 text-sm font-medium {{ $tab === $id ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                                {{ __("patients.$key") }}
                                @if ($id !== 'overview' && $payload['count'] > 0 && $tab === $id)
                                    <span class="ml-1 rounded-full bg-slate-100 px-1.5 text-xs">{{ $payload['count'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    </nav>
                </div>

                @if ($tab === 'overview')
                    <div class="card space-y-4 p-5">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-800">{{ __('patients.notes') }}</h2>
                            <p class="mt-1 whitespace-pre-wrap text-sm text-slate-700">{{ $patient->notes_non_clinical ?: '—' }}</p>
                            <p class="mt-2 text-xs text-amber-700">{{ __('patients.notesHint') }}</p>
                        </div>
                    </div>
                @elseif ($tab === 'sick')
                    @include('patients.partials.sick-leave', [
                        'rows' => $sickRows,
                        'patient' => $patient,
                        'editing' => $editing,
                        'timezone' => $timezone,
                        'today' => $today,
                    ])
                @else
                    <div class="card p-5 text-sm text-slate-500">{{ __('patients.tabEmpty') }}</div>
                @endif
            </div>

            <div class="space-y-5">
                {{-- Tags editor --}}
                <form method="POST" action="{{ route('patients.tags', $patient->id) }}" class="card space-y-3 p-4">
                    @csrf
                    <div class="flex flex-wrap items-center gap-2">
                        @forelse ($patient->tags as $tag)
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium text-white" style="background-color: {{ $tag->color }}">{{ $tag->name }}</span>
                        @empty
                            <span class="text-sm text-slate-500">{{ __('patients.noTags') }}</span>
                        @endforelse
                    </div>
                    <div>
                        <label class="label" for="tagsInput">{{ __('patients.tagsTitle') }}</label>
                        <input class="input-field" id="tagsInput" name="tags" type="text" list="patient-tag-options"
                               value="{{ old('tags', implode(', ', $currentTags)) }}" placeholder="{{ __('patients.tagsHint') }}">
                        <datalist id="patient-tag-options">
                            @foreach ($allTags as $tag)
                                <option value="{{ $tag->name }}"></option>
                            @endforeach
                        </datalist>
                        <p class="mt-1 text-xs text-slate-500">{{ __('patients.tagsCreateHint') }}</p>
                        @error('tags')
                            <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ field_error($message) }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="btn-secondary">{{ __('patients.saveTags') }}</button>
                </form>

                {{-- Stop messages --}}
                <div class="card space-y-3 p-4">
                    <h2 class="text-sm font-semibold text-slate-800">{{ __('patients.messagingTitle') }}</h2>
                    <p class="text-sm text-slate-600">
                        @if ($patient->do_not_message)
                            <span class="font-medium text-red-700">{{ __('patients.dnmActive') }}</span>
                            @if ($patient->do_not_message_reason)
                                <span class="text-slate-500">({{ $patient->do_not_message_reason }})</span>
                            @endif
                        @else
                            {{ __('patients.dnmInactive') }}
                        @endif
                    </p>

                    @if ($patient->do_not_message)
                        <form method="POST" action="{{ route('patients.dnm', $patient->id) }}">
                            @csrf
                            <input type="hidden" name="stop" value="false">
                            <button type="submit" class="btn-secondary">{{ __('patients.resumeMessages') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('patients.dnm', $patient->id) }}" class="space-y-3">
                            @csrf
                            <input type="hidden" name="stop" value="true">
                            <div>
                                <label class="label" for="dnmReason">{{ __('patients.stopReasonLabel') }}</label>
                                <textarea class="input-field min-h-16" id="dnmReason" name="reason" rows="2" required>{{ old('reason') }}</textarea>
                                @error('reason')
                                    <p role="alert" class="mt-1 text-xs font-medium text-red-600">{{ field_error($message) }}</p>
                                @enderror
                            </div>
                            <button type="submit" class="btn-danger">{{ __('patients.stopConfirm') }}</button>
                        </form>
                    @endif
                </div>

                {{-- Status toggle --}}
                <form method="POST" action="{{ route('patients.status', $patient->id) }}" class="card flex flex-wrap items-center justify-between gap-3 p-4"
                      data-confirm="{{ $patient->active ? __('patients.deactivateConfirm') : __('patients.reactivateConfirm') }}">
                    @csrf
                    <input type="hidden" name="active" value="{{ $patient->active ? 'false' : 'true' }}">
                    <p class="text-sm text-slate-600">{{ $patient->active ? __('patients.activeHint') : __('patients.inactiveHint') }}</p>
                    <button type="submit" class="btn-danger">{{ $patient->active ? __('patients.deactivate') : __('patients.reactivate') }}</button>
                </form>
            </div>
        </div>
    </div>

    @if ($patient->id_photo_url)
        <div data-id-photo-lightbox class="fixed inset-0 z-50 hidden items-center justify-center p-4">
            <button type="button" aria-label="{{ __('common.close') }}" class="absolute inset-0 bg-slate-900/70" data-id-photo-lightbox-close></button>
            <div role="dialog" aria-modal="true" aria-label="{{ __('patients.idPhotoView') }}"
                 class="relative z-10 max-h-[90vh] max-w-5xl overflow-auto rounded-xl border border-slate-200 bg-white p-3 shadow-xl">
                <img src="{{ $patient->id_photo_url }}" alt="" class="h-auto w-full max-w-full object-contain">
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        var input = document.getElementById('idPhoto');
        if (input) {
            var upload = document.querySelector('[data-id-photo-upload]');
            var capture = document.querySelector('[data-id-photo-capture]');
            var view = document.querySelector('[data-id-photo-view]');
            var lightbox = document.querySelector('[data-id-photo-lightbox]');
            var close = document.querySelector('[data-id-photo-lightbox-close]');
            var thumb = document.querySelector('[data-id-photo-thumb]');
            var objectUrl = null;
            if (upload) upload.addEventListener('click', function () { input.removeAttribute('capture'); input.click(); });
            if (capture) capture.addEventListener('click', function () { input.setAttribute('capture', 'environment'); input.click(); });
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
                if (!file || !thumb) return;
                objectUrl = URL.createObjectURL(file);
                thumb.src = objectUrl;
                thumb.classList.remove('hidden', 'border-dashed', 'bg-slate-50');
                if (view) view.classList.remove('hidden');
            });
            if (view && lightbox) view.addEventListener('click', function () { lightbox.classList.remove('hidden'); lightbox.classList.add('flex'); });
            if (close && lightbox) close.addEventListener('click', function () { lightbox.classList.add('hidden'); lightbox.classList.remove('flex'); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && lightbox && !lightbox.classList.contains('hidden')) {
                    lightbox.classList.add('hidden'); lightbox.classList.remove('flex');
                }
            });
        }

        // Confirm dialogs (status toggle, sick-leave delete) via data-confirm.
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
            });
        });
    })();
</script>
@endpush
