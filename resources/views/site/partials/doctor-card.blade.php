@php
    use App\Support\PublicSite\Format;

    $statusLabel = match ($doctor['status']) {
        'ON_DUTY_NOW' => __('site.doctors.availableNow'),
        'ON_LEAVE' => __('site.doctors.onLeave'),
        default => __('site.doctors.noSchedule'),
    };

    $statusClass = match ($doctor['status']) {
        'ON_DUTY_NOW' => 'bg-emerald-50 text-emerald-700',
        'ON_LEAVE' => 'bg-amber-50 text-amber-700',
        default => 'bg-slate-100 text-slate-600',
    };

    $photo = Format::publicDoctorImageUrl($doctor['id'], $doctor['photoUrl']);
@endphp

<article class="card p-4">
    <div class="flex items-center gap-3">
        @if ($photo)
            {{-- The doctor photos are 512x512 — the intrinsic size reserves
                 the space; a missing photo falls back to the initials. --}}
            <img src="{{ $photo }}" alt="{{ __t('site.doctors.photoAlt', ['name' => $doctor['name']]) }}"
                 width="512" height="512"
                 loading="lazy" decoding="async"
                 class="h-16 w-16 shrink-0 rounded-full object-cover"
                 data-fallback="initials"
                 data-fallback-text="{{ mb_strtoupper(mb_substr($doctor['name'], 0, 1)) }}">
        @else
            <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700" aria-hidden="true">
                {{ mb_strtoupper(mb_substr($doctor['name'], 0, 1)) }}
            </span>
        @endif
        <div class="min-w-0">
            <h3 class="truncate text-sm font-semibold text-slate-900">{{ $doctor['name'] }}</h3>
            @if ($doctor['specialty'])
                <p class="truncate text-xs text-slate-500">{{ $doctor['specialty'] }}</p>
            @endif
        </div>
    </div>

    <span class="mt-3 inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>

    @if ($doctor['todayShifts'] !== [])
        <ul class="mt-2 space-y-0.5 text-xs text-slate-600">
            @foreach ($doctor['todayShifts'] as $shift)
                <li>{{ $shift['start'] }}–{{ $shift['end'] }}</li>
            @endforeach
        </ul>
    @elseif ($doctor['nextShiftStartsAt'])
        <p class="mt-2 text-xs text-slate-600">
            {{ __t('site.doctors.nextAvailable', ['when' => Format::formatNextAvailable($doctor['nextShiftStartsAt'], 'Africa/Addis_Ababa', app()->getLocale())]) }}
        </p>
    @endif

    <div class="mt-3 flex items-center gap-3">
        <a href="/book" class="text-xs text-brand-700 hover:underline">{{ __('site.bookAppointment') }}</a>
        @if ($tel = Format::telHref(\App\Support\Settings::get('clinic_phone', '')))
            <a href="{{ $tel }}" class="text-xs text-brand-700 hover:underline">{{ __('site.doctors.callToBook') }}</a>
        @endif
    </div>
</article>
