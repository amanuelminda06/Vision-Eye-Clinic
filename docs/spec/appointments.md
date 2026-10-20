# Appointments, website requests, follow-ups — Laravel rebuild spec

Legacy: Next.js App Router + Prisma + zod + next-intl. Sources: `src/modules/appointments/*`,
`src/app/(app)/appointments|follow-ups/page.tsx`, `src/app/api/public/requests/route.ts`,
`src/app/api/appointments/export/route.ts`, `src/modules/public-site/*`, `prisma/schema.prisma`,
`prisma/migrations/20261008214303_appointments_followups/*` + `20261019090000_drop_appointment_requests/*`,
README checklist 31–34, tests. **Legacy note:** the whole appointment-request queue was deleted at
HEAD commit `8a1e734`; those parts are marked **[LEGACY]** (recoverable at `8a1e734^`) because README 32/34
still describe them. Timezone everywhere = `DEFAULT_TIMEZONE = 'Africa/Addis_Ababa'`; day buckets are
clinic-local, storage is UTC timestamptz.

## 1. Routes & pages (legacy URL → purpose)

| Legacy URL | Method | Purpose | Roles |
|---|---|---|---|
| `/appointments` | GET | Appointment board: list / day / week views, doctor filter, prev/today/next, New appointment, per-row edit + status transitions + follow-up/sale CTAs. `?view=list\|day\|week&day=YYYY-MM-DD&doctor=<staffId>` | OWNER, RECEPTION |
| `/follow-ups` | GET | Follow-up board: tabs `?status=open\|overdue\|done\|cancelled` (default `open`) + `?patient=<patientId>` filter; mark-done / cancel row actions | OWNER, RECEPTION |
| `/requests` **[LEGACY]** | GET | Website-request queue: tabs `?status=NEW\|CONTACTED\|CONVERTED\|DECLINED\|SPAM` (+ `All` with a red NEW count pill), request cards with waiting age, convert/decline/spam actions | OWNER, RECEPTION |
| `/api/public/requests` | POST | **Public unauthenticated intake** for the website "book an appointment" form. Current behaviour: auto-creates a `CONFIRMED` appointment (no queue). Excluded from the session gate — `src/proxy.ts` `config.matcher: ['/((?!_next\|api\|.*\\..*).*)']` skips `/api` | none (public) |
| `/api/appointments/export` | GET | CSV/XLSX export of the filtered board. Query: `format=csv\|xlsx` (default `csv`), `from`, `to` (`YYYY-MM-DD`, else ignored), `doctorId` (≤64), `patientId` (≤64), `status` (≤32). Wrong role → `403` body `"Forbidden"`; forced password change → redirect `/change-password` | OWNER, RECEPTION |
| `/book` | GET | Public booking page; renders `BookingForm` with `minDate` = clinic-local today, windows `['any','morning','afternoon','evening']`, active public services | public |
| `/patients/[id]` | GET | Patient detail embeds `PatientAppointmentsList` (all appointments of patient, cap via list = 500) | OWNER, RECEPTION |
| `/sales/new?...` | GET | Target of `RecordSaleLink` (prefill `patientId,doctorId,serviceId,appointmentId`) shown on COMPLETED rows | OWNER, RECEPTION |
| `/403` | GET | Denial target: `requirePageRole` redirects here for wrong role; forced password change redirects to `/change-password` | all |
| Sidebar (`NAV_MODULES`, pms module) | — | `/appointments` → `labelKey 'appointments'`, `/follow-ups` → `'followUps'`, both `roles: ['OWNER','RECEPTION']`; `/requests` entry removed at HEAD | — |

Public intake response contract (status → body): `429 {ok:false,error:'rateLimited'}`; `400 {ok:false,error:'invalidJson'}` (unparseable body); `422 {ok:false,error:'validationError',fields:{…}}` (zod, phone, turnstile `fields:{turnstileToken:'required'}`, or booking-date errors mapped to `fields:{preferredDate:'invalidDate'|'pastSlot'}`); `403` never (public); `503 {ok:false,error:'noDoctorAvailable'}`; `500 {ok:false,error:'unknownError'}`; success `200 {ok:true,id,appointment:{date,time,doctor}}` with header `Cache-Control: no-store` (honeypot drop → `200 {ok:true,id:null}` + `Cache-Control: no-store`).

## 2. Pure logic to port (constants.ts / logic.ts / schemas.ts)

**constants.ts**
- `APPOINTMENT_STATUSES = ['SCHEDULED','CONFIRMED','COMPLETED','NO_SHOW','CANCELLED']`; `isAppointmentStatus()` guard.
- `APPOINTMENT_STATUS_LABEL_KEYS` → `SCHEDULED:'statusScheduled' … CANCELLED:'statusCancelled'` (namespace `appointments`).
- `APPOINTMENT_SOURCES = ['STAFF','WEBSITE_REQUEST']`; label keys `STAFF:'sourceStaff'`, `WEBSITE_REQUEST:'sourceWebsite'`.
- `FOLLOW_UP_STATUSES = ['OPEN','DONE','CANCELLED']`; label keys `statusOpen|statusDone|statusCancelled`.
- `DURATIONS = [15,30,45,60,90]`, `DEFAULT_DURATION = 30`.
- `TIME_WINDOWS` (wall-clock slots a website request maps to): `any → 09:00–10:00`, `morning → 09:00–10:00`, `afternoon → 14:00–15:00`, `evening → 16:00–17:00` (labelKeys `windowAny|windowMorning|windowAfternoon|windowEvening`).
- `FOLLOW_UP_PRESETS = ['2w','1m','3m','6m','custom']`.
- Limits: `MAX_APPT_NOTES=300`, `MAX_CANCEL_REASON=300`, `MAX_FOLLOW_UP_REASON=300`, `MAX_REQUEST_NAME=100`, `MAX_REQUEST_MESSAGE=500`, `MAX_REQUEST_INTEREST=200`, `MAX_REQUEST_DATE_DAYS_AHEAD=180`.
- Regexes: `APPT_TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/` (24-h HH:MM), `DATE_RE = /^\d{4}-\d{2}-\d{2}$/`.
- **[LEGACY]** `REQUEST_STATUSES = ['NEW','CONTACTED','CONVERTED','DECLINED','SPAM']`, `REQUEST_STATUS_LABEL_KEYS = {NEW:'statusNew',CONTACTED:'statusContacted',CONVERTED:'statusConverted',DECLINED:'statusDeclined',SPAM:'statusSpam'}`, **`REQUEST_WAIT_WORKING_MINUTES = 120`** (the "wait minutes": request age ≥ 120 clinic-working minutes ⇒ flagged red).
- Phone regexes (`src/lib/phone.ts`, used by intake + quick-create): strip `/[\s\-().]/g`; `MOBILE_NATIONAL = /^0[97]\d{8}$/`, `MOBILE_SHORT = /^[97]\d{8}$/`, `MOBILE_E164 = /^251[97]\d{8}$/`; `+`-prefixed input must match `^251[97]\d{8}$` after the `+`; results become `+2519XXXXXXXX` / `+2517XXXXXXXX`. `parsePhone('')`/whitespace → `'required'`, else unparseable → `'invalidPhone'`; `formatPhoneDisplay(+251912345678) → 0912345678`.

**logic.ts (pure, port 1:1)**
- `BASE_TRANSITIONS`: `SCHEDULED → [CONFIRMED, CANCELLED, NO_SHOW]`; `CONFIRMED → [COMPLETED, CANCELLED, NO_SHOW]`; `COMPLETED|NO_SHOW|CANCELLED → []`. `TERMINAL = ['COMPLETED','NO_SHOW','CANCELLED']`; `isTerminalStatus(s)`.
- `transitionAllowed(from,to,{ownerOverride})`: `false` if `from===to` or either not a known status; if `from` terminal → `ownerOverride === true`; else membership in `BASE_TRANSITIONS[from]`.
- `isActiveStatus(s)` = `s==='SCHEDULED' || s==='CONFIRMED'` (drives every "active" query: double-book, day counts, doctor loads, HR conflicts).
- `findDoctorDoubleBookings(existing, candidate)`: filter `appt.id !== candidate.id && appt.doctorId === candidate.doctorId && isActiveStatus(appt.status) && appt.startsAt < candidate.endsAt && candidate.startsAt < appt.endsAt` (strict ⇒ back-to-back is **not** a clash).
- `isInsideShift(shifts, startsAt, endsAt)`: `some(s => s.startsAt <= startsAt && s.endsAt >= endsAt)` — window must fit **inside one** shift (instants, overnight shifts OK).
- `leaveCoversDateKey(leave,dateKey)`: only `leave.status==='APPROVED'` and `String(startDate).slice(0,10) <= dateKey <= String(endDate).slice(0,10)`.
- `appointmentCoverageWarnings(candidate, shifts, leaves, tz)`: push `'outsideShift'` when `!isInsideShift`; compute local day keys `clinicDateKey(startsAt)`/`(endsAt)`, iterate `dateKeysBetween(startKey,endKey,30)` (cap 30 days) and push `'onLeave'` if any day is covered by an APPROVED leave of `staffId===candidate.doctorId`. Never throws; returns stable keys `['outsideShift','onLeave']` (in that order).
- `resolvePatientMatch(patients, phoneE164)`: first `patient.phoneE164 === phoneE164` else `null` (exact E.164 match only).
- `slotForTimeWindow(window)`: lowercases/trims; matches `any|morning|afternoon|evening` to the table above; unknown/absent ⇒ `slots[0]` = `09:00–10:00`.
- `appointmentInstants(dateKey,startTime,durationMin,tz)`: `startsAt = combineDateAndTime(dateKey,startTime,tz)` (local wall clock → UTC), `endsAt = startsAt + max(1,durationMin)*60000`.
- `dateKeyAddMonths(baseKey,months)`: calendar-month add clamping to last day (`2026-01-31 +1m = 2026-02-28`).
- `followUpDueKey(baseKey,preset,customKey)`: `2w → base+14d`; `1m|3m|6m → dateKeyAddMonths`; `custom → String(customKey).slice(0,10)` (fallback `baseKey` if absent); unknown preset → `baseKey`.
- `workingMinutesBetween(from,to,hours[],tz)`: `0` if `from>=to`; walk clinic-local days (index `0 = Sunday` … `6 = Saturday`, from `working_hours` settings keys `sun..sat`), skip `closed` days, accumulate overlap of `[from,to)` with `[open,close)` local per day, advance cursor to next local midnight, `guard < 400` iterations, return `Math.floor(total/60000)`. Used only by **[LEGACY]** request-age widget.
- Week helpers (`src/modules/hr/shifts.ts`): `weekStartKey(k) = k - ((weekdayOf(k)+6)%7)` → **Monday-start week**; `weekDays(start) = 7 keys`; `dateKeysBetween(from,to,maxDays=400)` inclusive.

**schemas.ts** → see §3.

## 3. Validation schemas (fields, rules, error keys, 400/422 contract)

Zod messages are **i18n keys**, not text. Server helper `validate()` builds `fields` with `key = issue.path.join('.') || '_'`, first issue per key wins, throws `ValidationError`. Server actions never return HTTP codes — they return the envelope
`{ok:true,data?} | {ok:false,error?,fields?,existing?}` (see §4 for `forbidden` / `passwordChangeRequired`).

**`appointmentFormSchema`** (used by `saveAppointmentAction`, fields from `FormData`):
`id?` max 64 `'tooLong'`; `patientId?` max 64 `'tooLong'`; `quickName?` trim max 100 `'tooLong'`; `quickPhone?` trim max 32 `'tooLong'`; `doctorId` min 1 `'doctorRequired'` max 64 `'tooLong'`; `dateKey` `DATE_RE` `'invalidDate'`; `startTime` `APPT_TIME_RE` `'invalidTime'`; `durationMin` coerced int, min `15` max `180`, all errors `'invalidDuration'` (default submitted `'30'`); `serviceId?` max 64 `'tooLong'`; `notes?` trim max 300 `'tooLong'`; `overrideDoubleBooking` union boolean|`'true'|'false'` → boolean (absent = false).
Refine: `patientId` present **or** (`quickName` && `quickPhone` present) else `fields.patientId = 'patientRequired'`.

**`appointmentStatusSchema`**: `id` min 1 `'required'` max 64 `'tooLong'`; `status` enum of the 5 statuses `'invalidStatus'`; `cancelReason?` trim max 300 `'tooLong'`.

**`followUpFormSchema`**: `patientId` min 1 `'required'` max 64 `'tooLong'`; `appointmentId?` max 64 `'tooLong'`; `preset` enum `FOLLOW_UP_PRESETS` `'invalidPreset'`; `dueDate?` = `DATE_RE`-key or `''`; `reason?` trim max 300 `'tooLong'`. Refine: `preset !== 'custom' || dueDate` non-empty, else `fields.dueDate = 'invalidDate'`.

**`followUpStatusSchema`**: `id` min 1 `'required'` max 64 `'tooLong'`; `status` enum `['DONE','CANCELLED']` `'invalidStatus'`.

**[LEGACY] `requestStatusSchema`**: `id` min1 `'required'`/max64 `'tooLong'`; `status` enum `['NEW','CONTACTED','CONVERTED','DECLINED','SPAM']` `'invalidStatus'`; refine `status !== 'CONVERTED'` else `fields.status = 'convertViaForm'`.
**[LEGACY] `convertRequestSchema`**: `requestId` min1 `'required'`/max64; `doctorId` min1 `'doctorRequired'`/max64; `serviceId?` max64; `patientId?` max64; `notes?` trim max300 `'tooLong'`.
**[LEGACY] `publicRequestSchema`** (old intake body): `name` trim 1..100; `phone` trim 1..32; `preferredDate` DATE_RE; `preferredTimeWindow?` trim max 40 → `null`; `serviceInterest?` trim max 200 → `null`; `message?` trim max 500 → `null`; `language` enum `['en','am']` `'invalidLanguage'` default `'en'`.

**Public booking body `bookingFormSchema`** (`src/modules/public-site/schemas.ts`, current intake):
`name` trim 1..100 `'required'|'tooLong'`; `phone` trim 1..32 `'required'|'tooLong'` (semantic phone check happens after zod, in the route); `preferredDate` `DATE_RE` `'invalidDate'`; `preferredTimeWindow` enum `['any','morning','afternoon','evening']` `'invalidTimeWindow'` default `'any'`; `serviceInterest?` trim max 200 → `null`; `message?` trim max 500 → `null`; `language` enum `['en','am']` `'invalidLanguage'` default `'en'`; `consent` boolean|`'true'|'false'` → must be `true` else `'consentRequired'`; honeypot `website` max 0 `'spam'` default `''`; `turnstileToken?` max 4096 `'tooLong'`. `isHoneypotTripped(v) = v.website.trim().length > 0`.

**400/422 contract (public route only)**: unparseable JSON → **400** `{ok:false,error:'invalidJson'}`; any `ValidationError` → **422** `{ok:false,error:'validationError',fields}`; phone failure → 422 `fields:{phone:'required'|'invalidPhone'}`; turnstile failure → 422 `fields:{turnstileToken:'required'}`; booking `invalidDate`/`pastSlot` → 422 `fields:{preferredDate:<code>}`; rate limit → **429**; `noDoctorAvailable` → **503**; other thrown errors → **500** `{ok:false,error:'unknownError'}`. README 34 wording: invalid JSON → 400, bad fields/phone → 422 with field errors, valid payload → 200 `{ok:true,id}` (current code also echoes `appointment`), fast repeats → 429.

## 4. Role matrix (page / action / API)

Role arrays: `APPT_ROLES = ['OWNER','RECEPTION']`; `FOLLOW_UP_ROLES = ['OWNER','RECEPTION']`; `APPT_OWNER_ROLES = ['OWNER']`; **[LEGACY]** `REQUEST_ROLES = ['OWNER','RECEPTION']`. Guards: `canManageAppointments(r) = canManageFollowUps(r) = (r==='OWNER'||r==='RECEPTION')`; `canOverrideTerminalTransition(r) = r==='OWNER'`; **[LEGACY]** `canHandleRequests(r) = (r==='OWNER'||r==='RECEPTION')`.

| Surface | OWNER | RECEPTION | STAFF |
|---|---|---|---|
| Page `/appointments` (`requirePageRole(APPT_ROLES)`) | ✅ | ✅ | **redirect `/403`** |
| Page `/follow-ups` (`requirePageRole(FOLLOW_UP_ROLES)`) | ✅ | ✅ | **redirect `/403`** |
| Page `/requests` **[LEGACY]** (`requirePageRole(REQUEST_ROLES)`) | ✅ | ✅ | **redirect `/403`** |
| `saveAppointmentAction`, `setAppointmentStatusAction` (`withAuth(APPT_ROLES)`) | ✅ | ✅ | `{ok:false,error:'forbidden'}` |
| edit a **terminal** appointment (save path) | ✅ (audited update) | ❌ `{ok:false,error:'terminalLocked'}` | ❌ `terminalLocked` (checked before role-specific work) |
| move **out of** a terminal status (`ownerOverride: role==='OWNER'`) | ✅ | ❌ `terminalLocked` | ❌ `terminalLocked` |
| `createFollowUpAction`, `setFollowUpStatusAction` (`withAuth(FOLLOW_UP_ROLES)`) | ✅ | ✅ | `forbidden` |
| `convertRequestAction`, `setRequestStatusAction` **[LEGACY]** (`withAuth(REQUEST_ROLES)`) | ✅ | ✅ | `forbidden` |
| `GET /api/appointments/export` (`requireRole(APPT_ROLES)`) | ✅ | ✅ | `403 "Forbidden"` |
| `POST /api/public/requests` | public, no session required (proxy skips `/api`) ||||
| Forced password change (`mustChangePassword`) | pages → redirect `/change-password`; actions → `{ok:false,error:'passwordChangeRequired'}`; export → redirect `/change-password` ||||

Extra action-level errors (rendered by `AppointmentError`, keys under `appointments.*` unless noted):
`doctorRequired`, `notFound`, `terminalLocked`, `invalidTransition`, `cancelReasonRequired`, `requestAlreadyConverted` **[LEGACY]**, `patientRequired`, `patientInactive`; `common.*`: `forbidden`, `unknownError`, `validationError`, `passwordChangeRequired`. Validation failures → `{ok:false,error:'validationError',fields}` (rendered as `common.fixFields` list + per-field `validation.<key>`).

## 5. Audit actions (exact strings, entity names, diffed fields)

`writeAudit({user,entity,entityId,action,old,new,ip})` — old/new are **diffed** (`diffObjects`) so only changed keys persist; secrets redacted; never throws; `ip` defaults to first `x-forwarded-for` hop. Rows: `AuditLog {userId,entity,entityId,action,oldJson,newJson,ip}`.

| action | entity / entityId | `old` | `new` | notes |
|---|---|---|---|---|
| `APPOINTMENT_CREATED` (staff) | `Appointment` / new id | — | `patientId, doctorId, startsAt(ISO), endsAt(ISO), serviceId, notes, warnings[]` | after create |
| `APPOINTMENT_CREATED` (public) | `Appointment` / new id | — | `source:'WEBSITE', status:'CONFIRMED', doctorId, startsAt, endsAt, serviceId, ipHash`, `assignedDespiteClash:true` (only when clash) | `user:null`, **`ip:null`** (raw IP never stored) |
| `APPOINTMENT_UPDATED` | `Appointment` / id | `patientId, doctorId, serviceId, startsAt, endsAt` | same keys with new values | notes are **not** diffed |
| `APPOINTMENT_STATUS_CHANGED` | `Appointment` / id | `{status}` | `{status, cancelReason}` | every transition, incl. OWNER terminal override |
| `APPOINTMENT_DOUBLE_BOOKED` (**OWNER/staff override audit**) | `Appointment` / `candidateId ?? ''` | — | `doctorId, startsAt, endsAt, clashCount` | written **only** when `overrideDoubleBooking === true` **and** clash count > 0 |
| `FOLLOW_UP_CREATED` | `FollowUp` / id | — | `patientId, dueDate(YYYY-MM-DD), reason, createdFromAppointmentId` | |
| `FOLLOW_UP_STATUS_CHANGED` | `FollowUp` / id | `{status}` | `{status}` | |
| `EXPORTED` | `'appointment'` / `'export'` | — | `{from?, to?, format}` | from `auditExport` on every export download |
| **[LEGACY]** `REQUEST_CONVERTED` | `AppointmentRequest` / request id | — | `appointmentId, doctorId, patientId` (literal `'matched-or-created'` when the form sent no patient), `startsAt, endsAt` | |
| `REQUEST_STATUS_CHANGED` **[LEGACY]** | `AppointmentRequest` / id | `{status}` | `{status}` | |

OWNER-override audits (rule): the terminal-status correction itself is always audited as `APPOINTMENT_STATUS_CHANGED` (old status → new status); an OWNER edit of a terminal row is audited as `APPOINTMENT_UPDATED`. There is no separate `*_OVERRIDE` action string.

## 6. Business rules & invariants (numbered, exact)

1. **Status graph edges**: `SCHEDULED→CONFIRMED|CANCELLED|NO_SHOW`; `CONFIRMED→COMPLETED|CANCELLED|NO_SHOW`; `COMPLETED|NO_SHOW|CANCELLED→∅`. Self-transitions and unknown statuses are rejected (`invalidTransition`).
2. **Terminal states frozen unless OWNER override**: `COMPLETED/NO_SHOW/CANCELLED` are terminal. Non-OWNER moving out ⇒ `terminalLocked`; OWNER ⇒ allowed with `ownerOverride:true` (audited `APPOINTMENT_STATUS_CHANGED`). Same lock applies to *editing* a terminal appointment in `saveAppointmentAction` (`terminalLocked` for non-OWNER).
3. **Cancelling requires a reason**: target `CANCELLED` with blank `cancelReason` ⇒ `cancelReasonRequired`; stored trimmed or `null`. A reason is only written when the new status is `CANCELLED` (reopening keeps the old string).
4. **Double-book / outside-shift / on-leave are non-blocking warnings**: the save/update proceeds; response `data = {id, warnings[]}` where `warnings ⊆ ['doubleBooking','outsideShift','onLeave']` (that order). `doubleBooking` = ≥1 active (`SCHEDULED|CONFIRMED`) overlap for the same doctor, excluding the record itself. `outsideShift` = window not fully inside **one** shift. `onLeave` = any clinic-local day of the window (≤30 days) covered by an `APPROVED` leave.
5. **Override + audit**: the UI shows an amber "save anyway" prompt only for `doubleBooking`; resubmitting with `overrideDoubleBooking='true'` persists the row and writes `APPOINTMENT_DOUBLE_BOOKED` (`clashCount`). `outsideShift`/`onLeave` are display-only warnings — never audited, never blocking.
6. **Patient resolution (staff form)**: `patientId` must exist and `active` else `patientRequired`; otherwise quick-create — `normalizePhone(quickPhone)`; missing name/phone ⇒ `patientRequired`; existing phone ⇒ reuse (`active` false ⇒ `patientInactive`), else create `{fullName, phoneE164, createdBy: actorId}`.
7. **Doctor must be `active && isDoctor`** else `doctorRequired` (checked before any write); unknown appointment id ⇒ `notFound`.
8. **Public booking auto-approval** (`createPublicBooking`): reject `preferredDate` outside `[clinicToday, clinicToday+180d]` ⇒ `invalidDate`; map window → slot (§2) and build instants at `DEFAULT_DURATION = 30`; slot start `<= now` ⇒ `pastSlot`; no active doctors ⇒ `noDoctorAvailable` (503). Doctor assignment `pickBookingDoctor`: candidates = active doctors ordered `fullName asc`; busy = active appointments overlapping the slot (`take 500`); free pool = candidates not busy; if none free pool = all candidates (`assignedDespiteClash: true`); choose the least busy by that clinic-day count of active appointments (`groupBy doctorId`, day `[00:00, next-day 00:00)` local).
9. **Public patient link**: inside one transaction — match patient by exact `phoneE164`, else create `{fullName, phoneE164, preferredLanguage: language==='am'?'am':'en', createdBy:'public-booking'}` (marker constant `PUBLIC_BOOKING_ACTOR`, no FK). Service: when `serviceInterest` present, first `active` service with case-insensitive exact `name` match, else `null`.
10. **Public appointment row**: `status:'CONFIRMED'`, `createdFrom:'WEBSITE_REQUEST'`, `createdBy:'public-booking'`, `notes = message` + (only if no serviceId) `"Service of interest: <interest>"` joined by `\n`, sliced to 300. Then `APPOINTMENT_CREATED` audit with `ipHash` only (raw IP never stored) and the messaging hook; a messaging failure is logged (`[appointments] booking message hook failed:`) but **the booking still returns ok**.
11. **[LEGACY] Request conversion**: `convertRequestAction` — request missing ⇒ `notFound`; `appointmentId` set **or** `status==='CONVERTED'` ⇒ `requestAlreadyConverted`; doctor must be active+isDoctor ⇒ `doctorRequired`; slot = `slotForTimeWindow(request.preferredTimeWindow)` at 30 min on `request.preferredDate`; transaction: `patientId = form.patientId` **or** match by `phoneE164` **or** create `{fullName: request.name, phoneE164, createdBy: actor.id, preferredLanguage}`; create appointment `status:'SCHEDULED'`, `createdFrom:'WEBSITE_REQUEST'`, `createdBy: actor.id`, `notes` from form; update request `{status:'CONVERTED', handledBy, handledAt, patientId, appointmentId}`; audit `REQUEST_CONVERTED`.
12. **[LEGACY] Request status tabs**: `NEW / CONTACTED / CONVERTED / DECLINED / SPAM` (+ "All"); NEW drives a red count pill (`countNewRequests`), sidebar/dashboard badges `newRequests`/`statNewRequests`. `setRequestStatusAction`: missing ⇒ `notFound`; `CONVERTED` ⇒ `requestAlreadyConverted`; sets `status`, `handledBy = previous ?? actor`, `handledAt = previous ? keep : now`; audit `REQUEST_STATUS_CHANGED`. Quick actions: NEW → `Mark contacted` only; `DECLINED` needs `window.confirm('Decline this request?')`, `SPAM` needs `confirm('Mark this request as spam?')`; CONVERTED hides all quick actions; `Convert` button shown only for `NEW|CONTACTED`.
13. **Request age (wait minutes)**: `RequestAge` recomputes every 60 s `workingMinutesBetween(createdAt, now, hours, tz)`; urgent (red pill) when `minutes >= REQUEST_WAIT_WORKING_MINUTES = 120`.
14. **Follow-up due dates (clinic-local)**: base key = clinic-local **today**. `2w = +14 days`; `1m/3m/6m = calendar months with month-end clamp`; `custom = dueDate` (required by schema). Stored `dueDate = new Date('<key>T00:00:00.000Z')` (a `@db.Date`). Unknown preset falls back to `'2w'` at write time.
15. **Follow-up status**: only from `OPEN`; `DONE|CANCELLED` rows ⇒ `invalidTransition` (no OWNER override exists here). Tabs `open|overdue|done|cancelled` (default `open`); **overdue** = `status==='OPEN' && dueDate < clinic-local today 00:00`; count helper `countOpenFollowUpsOverdue` uses the same predicate.
16. **Rate-limited public intake**: key `public-booking:<first x-forwarded-for hop | 'unknown'>`, `max = 5`, `windowMs = 60*60_000` ⇒ `429 {ok:false,error:'rateLimited'}`. Honeypot filled ⇒ silently `200 {ok:true,id:null}` (`Cache-Control: no-store`) so bots learn nothing. Turnstile off unless `TURNSTILE_SECRET_KEY` set; missing token then ⇒ 422. Only `sha256(ip)` hex (`hashClientIp`) reaches storage/audit — never the raw IP.
17. **`Cache-Control: no-store`** on: public booking success + honeypot responses, and export downloads (CSV/XLSX).
18. **Board caps**: appointments queries `take: 500` (`listAppointments`, also export); follow-ups `take: 300`; patient filter options `take: 300`; **[LEGACY]** requests `take: 200` (default `limit ?? 200`); supporting-data caps: doctor shifts `200`, approved leave `500`, HR conflict appointments `100`, public booking clash scan `500`.
19. **Duration**: coerced integer minutes, legal range 15–180 (preset list 15/30/45/60/90), default 30; stored implicitly as `startsAt/endsAt`.
20. **Messaging side-effects**: every appointment create/update/status change calls `onAppointmentChanged({appointmentId, previousStartsAt})` (reschedule detected when `previousStartsAt` differs ⇒ cancels pending `APPT_REMINDER`/`APPT_SAME_DAY`); follow-up create/status calls `onFollowUpChanged`. Public booking queues an immediate confirmation SMS plus reminders.
21. **Notes are NON-CLINICAL** free text (schema comment: never diagnoses/care details).

## 7. Queries: list/day/week views, filters, sort, caps, exports

- `listAppointments({from,to,doctorId?,patientId?,status?})` → `where` built only from supplied keys; `startsAt >= from` (`gte`), `startsAt < to` (`lt`); `orderBy startsAt asc`; **`take 500`**; select incl. `patient{fullName,phoneE164}`, `doctor{fullName}`, `service{name}`; row shape `AppointmentRow` (flattened `patientName/patientPhoneE164/doctorName/serviceName`).
- View ranges on `/appointments` (`tz` from settings): **list** = `today .. today+14`; **day** = `anchor .. anchor`; **week** = `weekStartKey(anchor) .. +6` (Monday-start); all converted to `[local 00:00, next-local-day 00:00)` instants. `day` param validated with `DATE_RE` else falls back to today; `view` must be `day|week` else `list`.
- Grouping for week cards: `Map<clinicDateKey(startsAt,tz), rows>`; prev/next shift = `±1` day (day/list) or `±7` days (week).
- `countAppointmentsOnDay(dayKey)` → active only (`status in ['SCHEDULED','CONFIRMED']`) inside the local day.
- `listPatientAppointments(patientId)` = unfiltered by status/time (still cap 500); `countPatientAppointments`.
- `getStaffAppointmentsInPeriod(staffId,from,to)` → active overlaps `[from,to)`, `orderBy startsAt asc`, `take 100`, mapped to `{id,title:patient.fullName,startsAt}` (HR leave seam).
- `listFollowUps({status?,patientId?,dueBeforeKey?})` → `dueDate < combineDateAndTime(dueBeforeKey,'00:00',tz)` for the overdue tab; `orderBy dueDate asc`; **`take 300`**; row includes `patient{fullName,phoneE164}`.
- `getWorkingSchedule()` → `{timeZone, hours[0..6]}` with `0 = Sunday`, from settings `working_hours` keys `sun,mon,tue,wed,thu,fri,sat` (legacy request age + tests).
- **[LEGACY]** `listAppointmentRequests({status?,limit?})` → `where {status}` when given, `orderBy createdAt asc`, `take limit ?? 200`; `getAppointmentRequest(id)`; `countNewRequests()` = `count({status:'NEW'})`.
- **Export** `GET /api/appointments/export` → filename `appointments-<today ISO date>.csv` (xlsx swaps extension); columns exactly `['id','startsAt','endsAt','patient','patientPhone','doctor','service','status','createdFrom','notes']`; cell values = `id`, `formatInTz(startsAt,tz)`, `formatInTz(endsAt,tz)`, `patientName`, `phoneE164||''`, `doctorName`, `serviceName||''`, `status`, `createdFrom`, `notes||''`; headers `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="…"`, `Cache-Control: no-store`; audited `EXPORTED`.

## 8. i18n keys used (complete list)

- **`appointments.*`**: `title, viewList, viewDay, viewWeek, today, allDoctors, when, patient, doctor, service, noService, selectDoctor, date, startTime, duration, notes, notesHint, source, noAppointments, status, statusScheduled, statusConfirmed, statusCompleted, statusNoShow, statusCancelled, sourceStaff, sourceWebsite, newAppointment, editAppointment, edit, quickCreate, quickName, quickPhoneHint, patientSearchPlaceholder, saveAnyway, doubleBookingTitle, doubleBookingBody, warning.doubleBooking, warning.outsideShift, warning.onLeave, cancelAppointment, cancelReason, cancelReasonRequired, confirmDestructive, createFollowUp, presetLabel, preset.2w, preset.1m, preset.3m, preset.6m, preset.custom, dueDate, reason, reasonHint, recordSale, patientNoAppointments, startsAt, action.SCHEDULED, action.CONFIRMED, action.COMPLETED, action.NO_SHOW, action.CANCELLED, notFound, terminalLocked, invalidTransition, patientInactive` (+ **[LEGACY]** `requestAlreadyConverted`; plus `doctorRequired`/`patientRequired` are served from `validation.*`, see below).
- **`followups.*`**: `title, tabOpen, tabOverdue, tabDone, tabCancelled, patient, dueDate, reason, overdueTag, patientFilter, allPatients, markDone, cancelFollowUp, cancelConfirm, statusOpen, statusDone, statusCancelled`.
- **`validation.*` (field keys emitted by these schemas)**: `required, tooLong, invalidDate, invalidTime, invalidStatus, invalidDuration, invalidPreset, invalidPhone, patientRequired, doctorRequired, consentRequired`* , `maxLength`* (*`consentRequired` is only in the public form path; `maxLength` exists but is unused by these schemas). `convertViaForm` **[LEGACY]** (removed from messages at HEAD).
- **`common.*`**: `forbidden, unknownError, validationError, passwordChangeRequired, fixFields, close, cancel, save, saving, clear, status, noResults, previous, next`.
- **`site.book.*` (public form)**: `title, intro, name, namePlaceholder, phone, phoneHint, phonePlaceholder, preferredDate, timeWindow, windowAny, windowMorning, windowAfternoon, windowEvening, serviceInterest, serviceInterestPlaceholder, serviceNone, message, messageHint, messageOptional, messagePlaceholder, consent, requiredNote, submit, submitting, errorGeneric, errorValidation, errorRateLimited, errors.required, errors.tooLong, errors.invalidDate, errors.invalidTimeWindow, errors.consent, errors.pastSlot, successTitle, successBody, successBodyGeneric, successAgain`.
- **`export.formatCsv`, `export.formatXlsx`** (export buttons); **`nav` labels** `appointments`, `followUps`.
- **[LEGACY] `requests.*`**: `title, filterAll, statusNew, statusContacted, statusConverted, statusDeclined, statusSpam, submittedAt, windowAny, windowMorning, windowAfternoon, windowEvening, serviceInterest, message, convert, convertTitle, convertHint, assignDoctor, selectDoctor, notes, notesHint, markContacted, decline, spam, confirmDecline, confirmSpam, ageMinutes, waitingSince` (removed from `messages/en.json` + `am.json` at HEAD).
- **[LEGACY] dashboard keys removed at HEAD**: `dashboard.statNewRequests`, `dashboard.newRequests`.
- All keys exist in both `messages/en.json` and `messages/am.json` (parity), except the legacy `requests.*` namespace which was dropped from both.

## 9. Test inventory (file → test → assertions; re-implement in PHPUnit)

**`src/modules/appointments/logic.test.ts`** (vitest; TZ `Africa/Addis_Ababa`, helper `at(dateKey,time)`):
- `status transitions > moves forward along SCHEDULED -> CONFIRMED -> COMPLETED`: `transitionAllowed('SCHEDULED','CONFIRMED')=true`; `('CONFIRMED','COMPLETED')=true`; `('SCHEDULED','COMPLETED')=false`; `('CONFIRMED','SCHEDULED')=false`.
- `lets SCHEDULED/CONFIRMED cancel or no-show`: all four of `SCHEDULED|CONFIRMED → CANCELLED|NO_SHOW` = `true`.
- `rejects self-transitions and unknown statuses`: `('CONFIRMED','CONFIRMED')=false`; `('NOPE','CONFIRMED')=false`; `('CONFIRMED','NOPE')=false`.
- `terminal states are frozen unless the OWNER overrides`: for each of `COMPLETED|NO_SHOW|CANCELLED`: → `CONFIRMED`/`SCHEDULED` = `false`; with `{ownerOverride:true}` = `true`.
- `doctor double-booking warning > flags overlapping active appointments for the same doctor`: fixtures a1 (doc-1 CONFIRMED 09:00–09:45), a2 (doc-1 CANCELLED 10:00–10:45), a3 (doc-2 SCHEDULED 09:00–09:45); candidate doc-1 09:15–10:30 ⇒ ids `['a1']`.
- `ignores cancelled, other doctors and the record itself`: candidate `{id:'a1', doc-1, 09:00–09:45}` ⇒ `[]`.
- `treats adjacent windows as non-overlapping`: candidate doc-1 09:45–10:45 ⇒ `[]`.
- `shift / leave coverage warnings > no warnings when inside a shift and not on leave`: 09:30–10:00 within shift 08:00–17:00, leave on 2026-10-09 ⇒ `[]`.
- `warns when the window is outside any shift`: 07:00–07:30 ⇒ contains `'outsideShift'`.
- `warns during approved leave even if a shift exists`: 2026-10-09 09:00–09:30 (APPROVED leave that day) ⇒ contains `'onLeave'`.
- `handles an overnight shift as a covering window`: `isInsideShift(shifts, 22:00, 23:30) = true` (shift 20:00→next-day 04:00).
- `request conversion helpers > matches an existing patient by exact E.164`: `+251911223344 → 'p1'`; unknown `+251999999999 → null`.
- `maps time windows to wall-clock slots with a sane default`: `morning→09:00/10:00`; `AFTERNOON→14:00/15:00`; `evening→16:00/17:00`; `null→09:00/10:00`; `unknown→09:00/10:00`.
- `timezone handling > builds UTC instants from clinic-local day + time (+03:00 Addis)`: `appointmentInstants('2026-10-08','09:00',30)` ⇒ `2026-10-08T06:00:00.000Z` / `06:30:00.000Z`.
- `clinicDateKey round-trips across the overnight edge`: `'2026-10-08','23:00',90` ⇒ end `2026-10-08T21:30:00.000Z`.
- `follow-up due presets > computes 2 weeks and calendar months, clamping month-end`: `('2026-10-08','2w') = dateKeyAdd(+14)`; `('2026-01-31','1m') = '2026-02-28'`; `('2026-10-08','3m') = '2027-01-08'`; `('2026-10-08','6m') = '2027-04-08'`; `('2026-10-08','custom','2026-12-01') = '2026-12-01'`.
- `working minutes (request waiting time) > counts only open-time minutes within a day`: grid (0 Sun closed, 1–5 08:00–17:00, 6 Sat 08:00–12:00): Mon 08:00→10:00 = `120`; Mon 16:00→Tue 09:00 = `120`.
- `skips weekends`: Fri 12:00 → Mon 09:00 = `600` (Fri 300 + Sat 240 + Mon 60; Sunday closed).
- `returns 0 for empty or backwards windows`: reversed = `0`; equal = `0`.

**`src/modules/appointments/roles.test.ts`**:
- `appointments and follow-ups are OWNER + RECEPTION`: `APPT_ROLES = ['OWNER','RECEPTION']`; `FOLLOW_UP_ROLES = ['OWNER','RECEPTION']`.
- `STAFF is denied every appointment capability`: `APPT_ROLES` not containing `'STAFF'`; `canManageAppointments('STAFF')=false`, `('OWNER')=true`, `('RECEPTION')=true`.
- `only the OWNER overrides terminal status changes`: `canOverrideTerminalTransition('OWNER')=true`, `('RECEPTION')=false`, `('STAFF')=false`; `APPT_OWNER_ROLES = ['OWNER']`.
- **[LEGACY]** the pre-HEAD version also asserted `REQUEST_ROLES = ['OWNER','RECEPTION']` in the first test.

**`src/modules/public-site/schemas.test.ts` → `describe('bookingFormSchema')`**:
- `accepts a valid request and normalises optionals to null`: `serviceInterest === null`, `message === null`.
- `requires consent`: `consent:false` throws `ValidationError`.
- `rejects a bad date and an unknown time window`: `'01-11-2026'` throws; window `'midnight'` throws.
- `caps the message at 500 characters`: 501 chars throws; 500 accepted (`length === 500`).
- `detects a filled honeypot`: `website:'' → false`; `'http://spam' → true`.

**`src/lib/ratelimit.test.ts`** (public intake limiter): default limit `30/5min` blocks key after `RATE_LIMIT_MAX` allows (other key unaffected); custom `max:2, windowMs:60_000` blocks the 3rd call; bucket resets at `resetAt`; expired buckets pruned once map > 5 000; `resetRateLimits` clears.

**E2E `e2e/booking.spec.ts` → `website booking appears as an appointment in the ERP`**: unauth `POST /api/public/requests` with `{name,phone:'09'+stamp,preferredDate:+3 days,preferredTimeWindow:'any',serviceInterest:'',message,language:'en',consent:true,website:''}` → `response.ok()`; body `{ok:true}` and `appointment` matches `{date: preferredDate}` with truthy `appointment.doctor`; then login `RECEPTION`, `/appointments` shows the name within 15 s.

**[LEGACY] `e2e/requests.spec.ts` → `public request appears in the queue and converts`**: POST the same payload with today's date → `ok`; login `RECEPTION` → `/requests` shows the name (15 s); clicking the first link matching `/convert/i` reveals text `/book the appointment/i`.

**Gaps to cover in PHPUnit**: no unit tests for `actions.ts`/`queries.ts` (server actions), no `/api/public/requests` HTTP-status tests (400/422/429), and `e2e/roles.spec.ts` never asserts STAFF → `/403` on `/appointments` or `/follow-ups` (README 33 claims it) — port as feature tests.

## 10. UI behavior per page

**`/appointments` board**
- Header: title, `ExportLinks` (CSV + XLSX → `/api/appointments/export?format=…`), view switcher `List|Day|Week` (link preserves `view/day/doctor`; active = `btn-primary`), `New appointment`.
- Date nav: `Previous` / `Today` / `Next` (±1 day for list/day, ±7 for week) + auto-submitting doctor `<select>` (`All doctors` = empty value) in a GET form carrying hidden `view`/`day`.
- **Day/list**: table columns `When | Patient | Doctor | Service | Status | Source | (actions)`; `When` = `formatInTz(startsAt,tz,{weekday:'short',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'})`; service fallback `—`; empty → single row `common.noResults` (colSpan 7). Status pill classes: `SCHEDULED bg-slate-100 text-slate-600`, `CONFIRMED bg-emerald-50 text-emerald-700`, `COMPLETED bg-blue-50 text-blue-700`, `NO_SHOW bg-amber-50 text-amber-700`, `CANCELLED bg-red-50 text-red-700`. Source label via `sourceStaff|sourceWebsite`.
- **Week**: 7 cards (`md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7`) headed `weekday short + day number`; each row = `HH:mm · patientName`, `doctorName` (+ `· serviceName`), then the same row buttons; empty day → `noAppointments`.
- Row buttons (`AppointmentRowButtons`): `Edit` **hidden** when status is `CANCELLED` or `NO_SHOW`; status buttons = `APPOINTMENT_STATUSES.filter(next => transitionAllowed(current,next,{ownerOverride:canOverride}))` labelled `action.SCHEDULED='Confirm'`, `action.CONFIRMED='Complete'`, `action.COMPLETED='Reopen'`, `action.NO_SHOW='No-show'`, `action.CANCELLED='Cancel'` (styles: CANCELLED → `btn-danger`, NO_SHOW → `btn-secondary`, others `btn-primary`). `CANCELLED` opens a modal (`cancelAppointment`) with a required textarea (`cancelReasonRequired` placeholder); blank reason cannot submit. `NO_SHOW` requires `window.confirm('Mark this appointment as a no-show?')`. When `COMPLETED`: show `Create follow-up` (dialog) and `Record sale` (link to `/sales/new` prefilled with `patientId,doctorId,serviceId,appointmentId`).
- **Create/edit modal** (`AppointmentFormModal`): heading `New appointment` / `Edit appointment`. Patient block toggles between *type-ahead* (debounce **250 ms**, search starts at ≥2 chars via `searchPatientsAction`, hidden `patientId`, clear button) and *Quick-create patient* (`quickName`, `quickPhone` placeholder `09… or +251…`). Schedule fields: doctor (`selectDoctor` placeholder), service (`noService` = empty), date (default `new Date().toISOString().slice(0,10)` = **UTC today**), start time default `09:00`, duration `<select>` of `DURATIONS` (`30 min` labels, initial 30), notes (max 300, `notesHint`). Server failures render `common.fixFields` list of `validation.<key>` + `AppointmentError` + amber warning list `warning.<key>` for non-double-book warnings. When saved `warnings` contains `doubleBooking` and not yet acknowledged → amber panel `doubleBookingTitle` / `doubleBookingBody` with `Cancel` + `Save anyway`; **Save anyway** replays the last FormData with `overrideDoubleBooking='true'`. On success: `router.refresh()` + close.
- Denied: STAFF → `/403` (server render), actions → `common.forbidden`.

**`/follow-ups`**
- Tabs `Open | Overdue | Done | Cancelled` (URL `status`, default `open`) + auto-submit patient select (`allPatients` empty option, options = active patients ordered by name, take 300).
- Table `Patient | Due date | Reason | Status | (actions)`; patient name links to `/patients/{id}`; due date `dateStyle:'medium'` in clinic tz; overdue row (`OPEN` and due < local today 00:00) shows bold red date + `overdueTag` (`Overdue`); reason fallback `—`. Status pills: OPEN emerald, DONE slate, CANCELLED red.
- Row actions only when `status==='OPEN'`: `Mark done` (immediate) and `Cancel` (needs `window.confirm('Cancel this follow-up?')`); DONE/CANCELLED rows render no buttons (server also rejects).
- From a COMPLETED appointment row: `Create follow-up` dialog = hidden `patientId`/`appointmentId`, preset buttons `2 weeks|1 month|3 months|6 months|Custom date` (hidden `preset`), custom shows a date input defaulting to UTC today, optional `reason` (`reasonHint`), submit `createFollowUp`/`saving`.

**`/requests` [LEGACY]**
- Tabs `All` (+ red NEW count pill when the filter is `All`) then the five status tabs; empty → card with `common.noResults`.
- Card: name + status pill (`NEW red, CONTACTED amber, CONVERTED emerald, DECLINED/SPAM slate`), `tel:` link showing `formatPhoneDisplay`, preferred date, window label (`As soon as possible|Morning|Afternoon|Evening`), waiting-age pill (`{minutes} min waiting`, red ≥ 120 working minutes, tooltip `Waiting since {date}`), `Submitted {date}` line; body boxes `Interested in` / `Message`. Actions: `Convert to appointment` (only `NEW|CONTACTED`) opening the dialog below; `Mark contacted` (only NEW), `Decline`, `Spam` (both with confirms); CONVERTED rows show no actions.
- **Convert dialog**: heading `Book the appointment`, hint `The patient is matched by phone or created on the spot, then booked for the preferred slot.`, doctor select (required), optional appointment notes, hidden `requestId` + `patientId`, submit `Convert to appointment`; success closes + refreshes.

**`/book` (public)**
- Fields: name*, phone* (hint + tel), preferred date* (`min` = clinic-local today), time-window select (default `any`), service interest select (service names), message textarea (`max 500`, hint with `{max}`), hidden honeypot `website` (hidden + `aria-hidden` + `tabIndex -1`), optional Turnstile widget when `NEXT_PUBLIC_TURNSTILE_SITE_KEY` set, consent checkbox*.
- Submit: JSON `POST /api/public/requests` with `language` = active locale. `429` → `site.book.errorRateLimited`; other failures → banner `errorValidation` for `validationError` (field errors rendered through `site.book.errors.<code>` only for the client's recognised codes `required|tooLong|invalidDate|invalidTimeWindow|consent|pastSlot`; the schema's `'consentRequired'` and `invalidLanguage|spam` are not recognised, so they surface only via the banner), otherwise `errorGeneric`. Success → green panel `successTitle` + `successBody{date,time,doctor}` (or `successBodyGeneric`) echoing the server-approved slot, `successAgain` button resets the form and the Turnstile widget.
