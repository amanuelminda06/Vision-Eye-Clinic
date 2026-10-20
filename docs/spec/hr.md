# HR (shifts, leave, public status) — Laravel rebuild spec

Legacy source: Next.js 15 app router + Prisma + zod, `src/modules/hr/*` (read-only
extraction). Scope: weekly shift schedule, weekly shift templates, leave
requests/decisions/calendar/balance, dashboard on-duty widget, public
`/api/public/doctors` feed. Nothing here covers appointments, patients or sales.

Timezone: all wall-clock values are **clinic-local**; default
`DEFAULT_TIMEZONE = 'Africa/Addis_Ababa'`, overridable from Settings
(`timezoneFrom(getSettingsMap())`). All persisted instants are UTC.

---

## 1. Routes & pages (legacy URL → purpose)

### Pages (server-rendered, session required, `requirePageRole`)

| Legacy URL | Purpose | Gate |
| --- | --- | --- |
| `/schedule?week=YYYY-MM-DD` | Weekly shift grid (Mon–Sun). `week` must match `/^\d{4}-\d{2}-\d{2}$/` else today. | `HR_VIEW_ROLES` = OWNER, RECEPTION, STAFF |
| `/leave` | "My leave": request form + annual balance card + own request table + CSV/XLSX export links | `HR_LEAVE_ROLES` = OWNER, RECEPTION, STAFF |
| `/leave/inbox` | Pending leave requests with approve/reject decision form | `HR_OWNER_ROLES` = OWNER |
| `/leave/calendar?from=YYYY-MM-DD` | 30-day team leave table (REQUESTED + APPROVED). `from` invalid → today. | `HR_TEAM_LEAVE_ROLES` = OWNER, RECEPTION |
| `/dashboard` | Contains "Who is on duty now" widget (`getOnDutyDoctors`, all doctors incl. unpublished) | all 3 roles |

Wrong role on a page → redirect `/403`. Forced password change → redirect
`/change-password`. Unauthenticated → secret login URL.

### API routes

| Legacy URL | Purpose | Gate |
| --- | --- | --- |
| `GET /api/leave/export?format=csv\|xlsx&status=&from=&to=` | Leave export. `format` invalid → `csv`; `status` uppercased, invalid → ignored (all); `from`/`to` valid pair → team range query. | `HR_VIEW_ROLES`; STAFF always gets own rows only |
| `GET /api/public/doctors` | Public "on duty now" feed, **no session** (proxy matcher skips `/api`) | none |

Export response headers: `Content-Type: text/csv; charset=utf-8` (or
`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`),
`Content-Disposition: attachment; filename="leave-<YYYY-MM-DD>.csv|.xlsx"`,
`Cache-Control: no-store`. Columns, exact order:
`id, staff, type, startDate, endDate, halfDay, reason, status, decisionNote, createdAt`
(`createdAt` rendered clinic-local; dates as `YYYY-MM-DD`).

### Server actions (legacy form endpoints; all return `ActionResult`)

| Action | Roles | Notes |
| --- | --- | --- |
| `createShiftAction` | `HR_MANAGE_ROLES` (OWNER) | returns `{id}` |
| `updateShiftAction` | OWNER | needs form `id` |
| `deleteShiftAction` | OWNER | idempotent: missing row → `ok()` (no audit) |
| `createTemplateAction` | OWNER | **exported but no UI caller** |
| `deleteTemplateAction` | OWNER | **exported but no UI caller**; missing row → `ok()` |
| `previewTemplateAction` | OWNER | dry-run, no writes |
| `applyTemplateAction` | OWNER | writes rows + 1 summary audit |
| `copyLastWeekAction` | OWNER | writes rows + 1 summary audit |
| `requestLeaveAction` | `HR_LEAVE_ROLES` (all) | returns `{id}` |
| `approveLeaveAction` / `rejectLeaveAction` | `HR_OWNER_ROLES` (OWNER) + not-self | |
| `cancelLeaveAction` | `HR_LEAVE_ROLES` + ownership/status check | |

Envelope (`src/lib/action.ts`):
`{ok:true, data?}` | `{ok:false, error?, fields?, existing?}`.
`withAuth` maps `ValidationError → {error:'validationError', fields}`,
`AuthorizationError(FORBIDDEN) → {error:'forbidden'}`,
`PASSWORD_CHANGE_REQUIRED → {error:'passwordChangeRequired'}`; any other throw
is rethrown (500).

### Nav/menu (module `hrm`, `src/modules/nav/index.ts`)

`/staff` OWNER · `/users` OWNER · `/leave` all · `/leave/inbox` OWNER ·
`/leave/calendar` OWNER,RECEPTION · `/schedule` all. Same sets on the dashboard
quick links.

---

## 2. Pure logic to port

### 2.1 `constants.ts` (README calls these "shift windows / half-day hours"; the
legacy file actually holds enums, labels, colours and limits)

```ts
LEAVE_TYPES        = ['ANNUAL','SICK','UNPAID','OTHER']
LEAVE_TYPE_LABEL_KEYS = {ANNUAL:'typeAnnual', SICK:'typeSick', UNPAID:'typeUnpaid', OTHER:'typeOther'}
LEAVE_STATUSES     = ['REQUESTED','APPROVED','REJECTED','CANCELLED']
LEAVE_STATUS_LABEL_KEYS = {REQUESTED:'statusRequested', APPROVED:'statusApproved',
                           REJECTED:'statusRejected', CANCELLED:'statusCancelled'}
SHIFT_SOURCES      = ['TEMPLATE','MANUAL']
WEEKDAY_LABEL_KEYS = ['daySun','dayMon','dayTue','dayWed','dayThu','dayFri','daySat'] // 0 = Sunday
SHIFT_COLORS = ['#2563eb','#d946ef','#db2777','#d97706','#7c3aed',
                '#0891b2','#65a30d','#dc2626','#4f46e5','#059669'] // 10 entries
colorForIndex(i) = SHIFT_COLORS[((i % 10) + 10) % 10]     // defensive modulo, any i
MAX_NOTE = 200          // shift note
MAX_REASON = 300        // leave reason
MAX_DECISION_NOTE = 300 // approve/reject/cancel note
MAX_RANGE_DAYS = 62     // inclusive day cap for template generate/preview
MAX_TEMPLATE_SHIFTS = 400   // exported, UNUSED in legacy code
MAX_TEMPLATE_STAFF = 50     // exported, UNUSED in legacy code
TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/   // 24h HH:mm
DATE_RE = /^\d{4}-\d{2}-\d{2}$/
isLeaveType(v) / isLeaveStatus(v)       // string-in-list guards (unused at runtime)
```

Half-day semantics are **not** hour-based: a half day counts `0.5` days
(see `requestedDays`).

### 2.2 `shifts.ts` — shift geometry (pure; instants, never wall-clock strings)

- `shiftsOverlap(aStart,aEnd,bStart,bEnd)` — half-open `[a,b)`:
  `aStart < bEnd && bStart < aEnd`. Touching edges do **not** overlap.
- `isValidShift(start,end)` — `start < end`.
- `findOverlappingShift(existing, {staffId, startsAt, endsAt, ignoreId?})` —
  first row with `id !== ignoreId && staffId === candidate.staffId && overlap`.
- `isShiftActiveAt(shift, at)` — `at >= startsAt && at < endsAt`.
- `nextShiftAfter(shifts, at)` — earliest `startsAt >= at`, else `null`.
- `weekdayOf(dateKey)` — parse `Y-M-D` as **UTC**, `getUTCDay()` (0 = Sunday);
  timezone-independent.
- `dateColumnToKey(v)` — `@db.Date` normaliser: string → `v.slice(0,10)`,
  Date → `v.toISOString().slice(0,10)`.
- `isOvernight(startTime, endTime)` — `endTime <= startTime` (equal ⇒ overnight).
- `shiftInstantsForDay({startTime,endTime}, dateKey, tz)` —
  `startsAt = combine(dateKey, startTime)`; if overnight `endKey = dateKey+1`
  else `endKey = dateKey`; `endsAt = combine(endKey, endTime)`.
- `templateAppliesOn(template, dateKey)` (private) —
  `weekdayOf(dateKey) === template.weekday` AND `dateKey >= validFrom` AND
  (`validTo == null` OR `dateKey <= validTo`) — lexicographic key compare.
- `dateKeysBetween(fromKey,toKey,maxDays=400)` — inclusive key list; the loop is
  `for (i=0; i<=maxDays; i++)` push-then-break, so it yields **at most
  maxDays+1 keys** and truncates a longer range.
- `generateTemplateShifts(templates, fromKey, toKey, tz, maxDays=400)` —
  for each date key × each template → `shiftInstantsForDay`; result sorted by
  `startsAt` ascending; each item `{templateId, staffId, dateKey, startsAt, endsAt}`.
- `weekStartKey(dateKey)` — Monday-based: `sinceMonday = (weekdayOf+6)%7`,
  subtract days.
- `weekDays(startKey)` — exactly 7 keys from `startKey`.
- `shiftByDays({staffId,startsAt,endsAt,note}, days)` — add `days * 86_400_000`
  ms to both instants (pure ms arithmetic; no DST re-resolution), returns
  `note ?? null`.
- `dayKeyOf(input, tz)` — re-export of `clinicDateKey` (unused).

Datetime primitives to port (`src/lib/datetime.ts`):
`clinicDateKey` (tz parts → `YYYY-MM-DD`), `clinicTimeKey` (→ `HH:mm`),
`combineDateAndTime(dateKey,timeKey,tz)` (wall-clock in tz → UTC instant, with a
second offset pass across DST edges), `startOfDay` / `endOfDay` (local midnight,
exclusive next midnight), `dateKeyAdd(key,n)` (pure UTC calendar arithmetic).

### 2.3 `leave.ts` — leave geometry (pure; inclusive date keys)

- `countCalendarDays(startKey,endKey)` — parse as `…T00:00:00Z`; non-finite or
  `to < from` → **1**; else `round((to-from)/86400000) + 1`.
- `requestedDays({startDate,endDate,halfDay})` —
  `days = countCalendarDays(dateColumnToKey(start), dateColumnToKey(end))`;
  `halfDay ? Math.max(0.5, days - 0.5) : days`.
- `leaveRangesOverlap(aStart,aEnd,bStart,bEnd)` — inclusive:
  `aStart <= bEnd && bStart <= aEnd`.
- `findOverlappingLeave(existing, {staffId,startDate,endDate,ignoreId?})` —
  first row with `id !== ignoreId && staffId === candidate.staffId && rangesOverlap`.
- `leaveCoversDate(leave, dateKey)` — `status === 'APPROVED' && start <= key <= end`.
- `isOnLeaveAt(leaves, staffId, dateKey)` — any row for that staff covering the key.
- `leaveBalance(annualLeaveDays|null, leaves, year)` → `{entitlement, taken, remaining}`:
  `taken = Σ requestedDays(row)` over rows with `type==='ANNUAL' && status==='APPROVED'`
  and `dateColumnToKey(startDate).slice(0,4) === String(year)`; `entitlement = annualLeaveDays`
  (may be null); `remaining = entitlement === null ? null : entitlement - taken`.
- `leaveRange(request)` → `{fromKey,toKey}`; `todayKey(now,tz)` → `clinicDateKey`.

### 2.4 `public-status.ts` — leak-proof projection

- `PublicDoctorStatus = 'ON_DUTY_NOW' | 'ON_LEAVE' | 'NOT_ON_DUTY'`.
- `isPublicDoctor(staff)` = `isDoctor && showOnWebsite && active`.
- `buildPublicDoctorStatus({staff, shifts, leaves, now, timeZone})`:
  1. `todayKey = clinicDateKey(now, tz)`;
  2. `ownShifts = shifts.filter(s => s.staffId === staff.id)`;
  3. `onLeave` = exists leave for staff with `status==='APPROVED'` and
     `leaveCoversDate(leave, todayKey)`;
  4. `activeShift` = first own shift active at `now`;
  5. `status = onLeave ? 'ON_LEAVE' : activeShift ? 'ON_DUTY_NOW' : 'NOT_ON_DUTY'`
     (**approved leave wins even mid-shift**);
  6. `todayShifts` = own shifts overlapping `[startOfDay(now), endOfDay(now))`
     (strict: `startsAt < dayEnd && endsAt > dayStart`), sorted `startsAt` asc,
     mapped to `{start: clinicTimeKey(startsAt), end: clinicTimeKey(endsAt)}`;
  7. `next = activeShift ?? nextShiftAfter(ownShifts, now)`;
  8. return **exactly** `{id, name, specialty, photoUrl, status, todayShifts,
     nextShiftStartsAt}` where `name = staff.fullName` and
     `nextShiftStartsAt = next ? next.startsAt.toISOString() : null`.
- Test helpers (keep or drop): `contextDateKeys(now, tz, days)`,
  `dayStartInstants(dateKey, tz)` → `{from: day 00:00, to: next day 00:00}`.

### 2.5 `roles.ts` — HR role sets

```ts
HR_VIEW_ROLES       = ['OWNER','RECEPTION','STAFF']  // schedule + own leave + export
HR_TEAM_LEAVE_ROLES = ['OWNER','RECEPTION']          // team leave calendar
HR_MANAGE_ROLES     = ['OWNER']                      // shifts + templates
HR_LEAVE_ROLES      = ['OWNER','RECEPTION','STAFF']  // request/cancel own leave
HR_OWNER_ROLES      = ['OWNER']                      // decide leave
canManageSchedule(role)  = role === 'OWNER'
canViewTeamSchedule(role)= role === 'OWNER' || role === 'RECEPTION'
canViewTeamLeave(role)   = role === 'OWNER' || role === 'RECEPTION'
canDecideLeave(role)     = role === 'OWNER'
canRequestForOthers(role)= role === 'OWNER'
isSelfApproval(actorStaffId, requestStaffId) =
    !!actorStaffId && actorStaffId === requestStaffId   // false when actor has no staff link
canCancelLeave({role, isSelf, status}):
    if (status === 'CANCELLED' || status === 'REJECTED') return false
    if (role === 'OWNER') return true
    return isSelf && status === 'REQUESTED'
```

### 2.6 `index.ts` barrel

Re-exports everything above plus all queries/actions. `SHIFT_COLORS`,
`colorForIndex`, `isLeaveType`, `isLeaveStatus`, `dayKeyOf`, `isOnLeaveAt`,
`leaveRange`, `getShift`, `createTemplateAction`, `deleteTemplateAction` are
exported but never called by a page/component — keep only if you want parity.

---

## 3. Validation schemas (zod → Laravel `FormRequest` / validator)

Every message is an **i18n key**, never a sentence. On failure the action
returns `{error:'validationError', fields:{[path]: key}}`; path is `issue.path
joined with '.'`, or `_` when the issue has no path. Only the **first** key per
path is kept. Client renders `validation.<key>` per field and
`common.validationError` as the banner.

Shared primitives:
- `dateKey = string` matching `DATE_RE`, key `invalidDate`
- `timeKey = string` matching `TIME_RE`, key `invalidTime`
- `checkbox = boolean | 'true' | 'false'` → coerced to boolean

### `shiftFormSchema` (create + update shift)

| field | rules | key |
| --- | --- | --- |
| `id` | optional, `max(64)` | `tooLong` |
| `staffId` | required `min(1)`, `max(64)` | `required`, `tooLong` |
| `dateKey` | `DATE_RE` | `invalidDate` |
| `startTime` | `TIME_RE` | `invalidTime` |
| `endTime` | `TIME_RE` | `invalidTime` |
| `note` | trim, `max(200)`, optional | `tooLong` |

Refine: `startTime !== endTime`, else message `invalidTime` on path `endTime`.

### `templateFormSchema`

| field | rules | key |
| --- | --- | --- |
| `staffId` | `min(1)`, `max(64)` | `required`, `tooLong` |
| `weekday` | coerced int, `0..6` | `invalid` |
| `startTime` / `endTime` | `TIME_RE` | `invalidTime` |
| `validFrom` | `DATE_RE` | `invalidDate` |
| `validTo` | `DATE_RE` or `''`, optional | `invalidDate` |

Refines: `startTime !== endTime` → `invalidTime` on `endTime`;
`!validTo || validTo >= validFrom` → `endBeforeStart` on `validTo`.

### `generateFromTemplateSchema` (preview + apply)

| field | rules | key |
| --- | --- | --- |
| `staffId` | optional, `max(64)` | `tooLong` |
| `fromKey` / `toKey` | `DATE_RE` | `invalidDate` |

Refine: parse both as UTC midnight; both finite **and** `to >= from` **and**
`(to - from)/86400000 + 1 <= 62` → else `rangeTooLong` on path `toKey`.

### `leaveRequestSchema`

| field | rules | key |
| --- | --- | --- |
| `staffId` | optional, `max(64)` | `tooLong` |
| `type` | enum of `LEAVE_TYPES` | `invalidLeaveType` |
| `startDate` / `endDate` | `DATE_RE` | `invalidDate` |
| `halfDay` | checkbox coercion | — |
| `reason` | trim, `max(300)`, optional | `tooLong` |

Refine: `endDate >= startDate` → `endBeforeStart` on `endDate`.
**No range-length cap on leave requests** (see rule 14).

### `leaveDecisionSchema` / `leaveCancelSchema` (identical shape)

| field | rules | key |
| --- | --- | --- |
| `leaveId` | `min(1)`, `max(64)` | `required`, `tooLong` |
| `note` | trim, `max(300)`, optional | `tooLong` |

Action-level (non-field) keys returned via `error`:
`shiftOverlap`, `leaveOverlap`, `cannotDecideOwnLeave`, `leaveNotPending`,
`cannotCancel`, `staffRequired`, `unknownError`, `forbidden`,
`validationError`, `passwordChangeRequired`.

---

## 4. Role matrix (page / action / API × role)

| Surface | OWNER | RECEPTION | STAFF |
| --- | --- | --- | --- |
| `GET /schedule` page | ✔ team grid | ✔ team grid | ✔ own rows only |
| Add-shift button | ✔ | ✘ (not rendered) | ✘ |
| Edit / delete shift (per cell) | ✔ | ✘ | ✘ |
| Template panel (preview/apply/copy) | ✔ | ✘ | ✘ |
| `createShiftAction` / `updateShiftAction` / `deleteShiftAction` | ✔ | `forbidden` | `forbidden` |
| `createTemplateAction` / `deleteTemplateAction` / `previewTemplateAction` / `applyTemplateAction` / `copyLastWeekAction` | ✔ | `forbidden` | `forbidden` |
| `GET /leave` page | ✔ | ✔ | ✔ |
| Request form (own) | ✔ | ✔ | ✔ |
| Request-form staff picker (for others) | ✔ | ✘ (not rendered) | ✘ |
| `requestLeaveAction` for self | ✔ | ✔ | ✔ |
| `requestLeaveAction` for another staff | ✔ (form `staffId` honoured) | ignored → self | ignored → self |
| Own request table (latest 100) | ✔ | ✔ | ✔ |
| Balance card | ✔ (if user linked to staff) | ✔ (if linked) | ✔ |
| Cancel own `REQUESTED` | ✔ | ✔ | ✔ |
| Cancel any `REQUESTED`/`APPROVED` | ✔ | ✘ (`cannotCancel`) | ✘ (`cannotCancel`) |
| `GET /leave/inbox` page | ✔ | `/403` | `/403` |
| `approveLeaveAction` / `rejectLeaveAction` | ✔ unless own request | `forbidden` | `forbidden` |
| `GET /leave/calendar` page (30-day team table) | ✔ | ✔ | `/403` |
| `GET /api/leave/export` | team history | team history | own rows only |
| `GET /api/public/doctors` | public | public | public |
| Dashboard on-duty widget | all doctors | all doctors | all doctors |

**Segregation of duties:** *nobody decides their own leave.* The check is
`isSelfApproval(user.staffId, request.staffId)` — exact equality of the two
staff ids; a user account with `staffId = null` (typical OWNER/RECEPTION) is
never "self", so a **peer with the highest role** performs the decision. On
match the action fails with `cannotDecideOwnLeave` **before** any write.

Menu visibility mirrors the table: nav module `hrm` shows `/leave/inbox` only to
OWNER and `/leave/calendar` to OWNER+RECEPTION; STAFF reaching `/leave/inbox`
gets `/403` (smoke test asserts the redirect body contains
`NEXT_REDIRECT;replace;/403`).

---

## 5. Audit actions (exact strings)

`writeAudit({user, entity, entityId, action, old?, new?})` — old/new are
**diffed** (`diffObjects`), only changed fields persist, secrets are
`[REDACTED]`, and a failed audit write **never** rolls back the business op.

| action | entity | entityId | `old` (diffed) | `new` (diffed) |
| --- | --- | --- | --- | --- |
| `SHIFT_CREATED` | `Shift` | `shift.id` | — | `{staff: fullName, startsAt: ISO, endsAt: ISO, source:'MANUAL', note: string\|null}` |
| `SHIFT_UPDATED` | `Shift` | `shift.id` | `{staffId, startsAt: ISO, endsAt: ISO, note}` | `{staffId, startsAt: ISO, endsAt: ISO, note}` |
| `SHIFT_DELETED` | `Shift` | `shift.id` | `{staffId, startsAt: ISO, endsAt: ISO, source, note}` | — |
| `SHIFT_TEMPLATE_CREATED` | `ShiftTemplate` | `template.id` | — | `{staff: fullName, weekday, startTime, endTime, validFrom, validTo: string\|null}` |
| `SHIFT_TEMPLATE_DELETED` | `ShiftTemplate` | `template.id` | `{staffId, weekday, startTime, endTime}` | — |
| `SHIFTS_GENERATED` | `Shift` | `` `template:${fromKey}:${toKey}` `` | — | `{from, to, staffId: string\|null, created, skipped}` |
| `SHIFTS_COPIED` | `Shift` | `` `copy:${sourceStart}:${targetStart}` `` | — | `{from: sourceStart, to: targetStart, created, skipped}` |
| `LEAVE_REQUESTED` | `LeaveRequest` | `leave.id` | — | `{staff: fullName, type, startDate, endDate, halfDay, reason: string\|null}` |
| `LEAVE_APPROVED` | `LeaveRequest` | `leave.id` | `{status:'REQUESTED'}` | `{status:'APPROVED', decisionNote: string\|null}` |
| `LEAVE_REJECTED` | `LeaveRequest` | `leave.id` | `{status:'REQUESTED'}` | `{status:'REJECTED', decisionNote: string\|null}` |
| `LEAVE_CANCELLED` | `LeaveRequest` | `leave.id` | `{status: <prior status>}` | `{status:'CANCELLED', note: string\|null}` |
| `EXPORTED` | `leaveRequest` | `export` | — | `{status: '<STATUS>'\|'all', format: 'csv'\|'xlsx'}` |

Notes:
- `SHIFTS_GENERATED` / `SHIFTS_COPIED` are written **only when `created > 0`**.
- ISO = `Date.toISOString()` (UTC, ms precision).
- `SHIFT_UPDATED` does **not** include `source` (source is immutable).
- No audit row when delete finds no row (action still returns `ok`).

---

## 6. Business rules & invariants (numbered, exact numbers)

1. **Clinic-local day keys.** Every date bucket is `YYYY-MM-DD` computed in the
   clinic timezone; `DATE_RE = /^\d{4}-\d{2}-\d{2}$/`. Dates stored as
   `@db.Date` are written as `new Date('<key>T00:00:00.000Z')` (UTC midnight).
2. **Shifts may cross midnight.** Template/shift times are `HH:mm`; a shift is
   overnight iff `endTime <= startTime` (`isOvernight`); the end instant lands on
   `dateKey + 1`. Grid renders `start–end` with a `+1` suffix when
   `clinicDateKey(endsAt) !== clinicDateKey(startsAt)`.
3. **Zero-length shifts are invalid**: `startTime === endTime` fails with
   `invalidTime` on `endTime`; persisted shifts must satisfy `startsAt < endsAt`.
4. **Same-person overlap is rejected.** Half-open `[startsAt, endsAt)`; overlap
   iff `aStart < bEnd && bStart < aEnd`; only rows with the same `staffId`
   count; the row being edited is excluded via `ignoreId`. Failure key
   `shiftOverlap` = "This shift overlaps an existing shift for the same staff member."
5. **Shift days are instants, not strings** — all overlap/generation math runs
   on UTC instants (day keys are for bucketing/display only).
6. **Weekly templates**: `weekday ∈ 0..6` (0 = Sunday), `validFrom` inclusive,
   `validTo` inclusive or `NULL`, `validTo >= validFrom`. Generation walks the
   inclusive date range, matches weekday + validity window, and is capped at
   `maxDays + 1` keys (`maxDays = 400` default).
7. **Weeks are Monday-based**: `weekStartKey = dateKey − ((weekdayOf+6)%7)`;
   `weekDays()` always returns 7 keys; the schedule range is
   `[startKey, startKey+7)` local midnights.
8. **Template range cap**: preview/apply accept at most **62** inclusive days
   (`MAX_RANGE_DAYS = 62`) → `rangeTooLong` on `toKey`.
9. **Apply skips overlaps** (never overwrites): generated rows clashing with an
   existing shift of the same staff are counted as `skipped`, the rest written
   with `source = 'TEMPLATE'`. Preview and apply use identical computation.
10. **Copy last week** copies `[targetStart−7, targetStart)` into
    `[targetStart, targetStart+7)` (both local-midnight bounds), shifting every
    instant by exactly `7 * 86_400_000` ms, preserving `note`, writing
    `source = 'MANUAL'`; clashing copies are skipped. Invalid/absent `weekStart`
    param falls back to today's key before `weekStartKey`.
11. **Whole or half days only.** `requestedDays = countCalendarDays` for full
    days, minus `0.5` when `halfDay` is set, floored at `0.5`
    (`Math.max(0.5, days - 0.5)`). `countCalendarDays` returns at least `1`
    (malformed/reversed input → `1`).
12. **Balance = `annualLeaveDays` − approved.** `entitlement` is
    `Staff.annualLeaveDays` (nullable); `taken` sums `requestedDays` of rows with
    `type = ANNUAL` **and** `status = APPROVED` whose **start date** falls in the
    requested calendar year; `remaining = entitlement − taken`, or `null` when
    entitlement is unset (UI shows "No annual leave entitlement set for this
    staff member."). Year = first 4 chars of today's clinic-local key.
13. **Overlap with an existing APPROVED leave is rejected** at request time
    (`leaveOverlap` = "This request overlaps an existing approved leave.") for
    the same `staffId`, inclusive ranges. Pending/rejected/cancelled requests do
    **not** block a new request.
14. **No range-length cap on leave requests.** README checklist 27 claims a
    "range-too-long" rejection for leave; in the legacy code `MAX_RANGE_DAYS`
    is enforced **only** on template generation. Only `endDate >= startDate`
    applies (`endBeforeStart`). See "Ambiguities".
15. **Approved leave suppresses website duty.** `leaveCoversDate` requires
    `status === 'APPROVED'`; on a covered clinic-local day the public status is
    `ON_LEAVE` (renders "On leave") **even while a shift is running**. Priority:
    `ON_LEAVE` > `ON_DUTY_NOW` > `NOT_ON_DUTY`.
16. **Self-approval is impossible.** Approve/reject require `status = REQUESTED`
    (else `leaveNotPending`) and fail with `cannotDecideOwnLeave` when
    `actor.staffId === request.staffId`. The OWNER cannot decide their own
    leave — a peer decides.
17. **Decision writes** set `status` (`APPROVED`/`REJECTED`), `decidedBy =
    actor.id`, `decidedAt = now`, `decisionNote = note || null`. Rejecting a
    non-`REQUESTED` row → `leaveNotPending` (no write).
18. **Cancellation**: allowed only for `REQUESTED` or `APPROVED` (otherwise
    `leaveNotPending`); OWNER may cancel any such row; a non-OWNER only their
    **own** row **and** only while `status = REQUESTED`, else
    `cannotCancel` ("Only an owner can cancel an approved request.").
    Result: `status = CANCELLED`, `decidedBy`, `decidedAt` set (note is **not**
    persisted on the row, only in the audit `new.note`).
19. **Request target staff**: default is the actor's linked `staffId`; only an
    OWNER's submitted `staffId` overrides it. Missing link or unknown staff →
    `staffRequired`. Shift creation additionally requires `staff.active = true`
    (templates only require the staff row to exist).
20. **Leave is created with `status = 'REQUESTED'`** and `createdBy = actor.id`;
    no balance check happens on request or approval.
21. **Appointment-collision hint**: for each pending request, count of that
    doctor's appointments with `status ∈ {SCHEDULED, CONFIRMED}` overlapping
    `[startDate, endDate)` **as UTC-midnight instants** (`startsAt < end &&
    endsAt > start`), `take: 100`, ordered by `startsAt`. Shown only when `> 0`
    as `{count} appointment(s) fall in this period.` — note the window ends at
    the last day's local-midnight-equivalent UTC instant, so the final day is
    effectively excluded (legacy quirk).
22. **Team leave calendar window is 30 days**: `toKey = fromKey + 29`;
    prev/next navigation steps ±30 days; query includes `REQUESTED` and
    `APPROVED` only, `take: 500`.
23. **Public publication gate**: `isDoctor && showOnWebsite && active`.
24. **Public projection is a strict allow-list** — exactly 7 keys:
    `id, name, specialty, photoUrl, status, todayShifts, nextShiftStartsAt`.
    Never phone, email, `annualLeaveDays`, `jobTitle`, `createdAt`, leave
    reasons/types, shift notes, or internal ids of leave/shift records.
25. **`todayShifts` uses clinic-local `HH:mm`** and includes any shift
    intersecting today's local day (an overnight shift starting yesterday and
    ending today would qualify via `endsAt > dayStart`).
26. **`nextShiftStartsAt`** = start of the currently running shift, else the
    earliest shift starting at/after `now`, else `null`; ISO-8601 UTC string.
27. **Feed/duty sorting**: dashboard & public list sort by status rank
    (`ON_DUTY_NOW:0, ON_LEAVE:1, NOT_ON_DUTY:2`) then `name.localeCompare`.
28. **Cap constants**: `MAX_NOTE 200`, `MAX_REASON 300`, `MAX_DECISION_NOTE
    300`, `MAX_RANGE_DAYS 62`, `MAX_TEMPLATE_SHIFTS 400`,
    `MAX_TEMPLATE_STAFF 50` (last two unused in legacy).
29. **Deletes are idempotent**: missing shift/template row → `ok()` with no
    audit. Update with missing `id`/row → `unknownError`.
30. **No hard delete of staff** cascades: `Shift`, `ShiftTemplate`,
    `LeaveRequest` all `onDelete: Cascade` from `Staff`.

### Prisma models → tables

- `ShiftTemplate(id cuid, staffId FK cascade, weekday Int, startTime String,
  endTime String, validFrom Date, validTo Date?, createdAt, updatedAt)`,
  index `(staffId, weekday)`.
- `Shift(id cuid, staffId FK cascade, startsAt timestamptz, endsAt timestamptz,
  source ShiftSource default MANUAL, note?, createdBy String /* no FK */, createdAt,
  updatedAt)`, indexes `(staffId, startsAt)` and `(startsAt)`.
- `LeaveRequest(id cuid, staffId FK cascade, type LeaveType, startDate Date,
  endDate Date, halfDay Boolean default false, reason?, status LeaveStatus
  default REQUESTED, decidedBy?, decidedAt?, decisionNote?, createdBy String,
  createdAt, updatedAt)`, indexes `(staffId, startDate)`, `(status)`.
- `Staff.annualLeaveDays Int?` — entitlement in days; `NULL` ⇒ no balance shown.
- Enums: `LeaveType {ANNUAL, SICK, UNPAID, OTHER}`;
  `LeaveStatus {REQUESTED, APPROVED, REJECTED, CANCELLED}`;
  `ShiftSource {TEMPLATE, MANUAL}`.

---

## 7. Queries: who sees what, caps, contracts

| Query | Filter | Order | Cap |
| --- | --- | --- | --- |
| `listStaffOptions()` | `active = true` | `isDoctor desc`, `fullName asc` | none |
| `getStaffOption(id)` | by id | — | 1 |
| `getUserStaffId(userId)` | `user.staffId` | — | nullable |
| `listShiftsInRange(from, to, staffId?)` | `startsAt < to && endsAt > from` (+ staff) | `startsAt asc` | none |
| `getShift(id)` | by id | — | 1 |
| `listTemplates(staffId?)` | optional staff | `fullName asc, weekday asc, startTime asc` | none |
| `listLeaveForStaff(staffId)` | by staff | `createdAt desc` | **100** |
| `listLeaveByStatus(status)` | by status | `createdAt asc` | **100** |
| `listTeamLeaveInRange(fromKey,toKey)` | `status ∈ {REQUESTED, APPROVED}`, `startDate <= toKey`, `endDate >= fromKey` | `startDate asc` | **500** |
| `getLeave(id)` | by id | — | 1 |
| `listPendingLeaveRequests()` | `REQUESTED` + per-row appointment count | `createdAt asc` | **100** |
| `countPendingLeave()` | `status = REQUESTED` | — | count |
| `getLeaveBalance(staffId, year)` | `type = ANNUAL && status = APPROVED` (all years; filtered in code by start-year) | — | none |
| `listApprovedLeaveCovering(staffIds, dateKey)` | `APPROVED && staffId ∈ ids && startDate <= day && endDate >= day` | — | none (empty ids → `[]`) |
| `getDoctorDutyStatuses(now, tz, publicOnly)` staff | `active && isDoctor` (+`showOnWebsite` when public) | `fullName asc` | none |
| … shifts | `staffId ∈ ids && endsAt >= startOfDay(now)` | `startsAt asc` | **1000** |
| `listLeaveByStatus` used by export (per status) | see above | `createdAt asc` | **100 per status**, then merged + sorted `createdAt desc` client-side |

Every leave row is decorated with `staffName` (from `staff.fullName`) and
`decidedByName` (batch-resolved from `User.name`, `NULL` if unknown).

### `LeaveRow` shape (shared by inbox/my-leave/calendar/export)

`{id, staffId, staffName, type, startDate, endDate, halfDay, reason, status,
decidedBy, decidedByName, decidedAt, decisionNote, createdBy, createdAt}` +
`appointmentConflicts: number` on inbox rows.

### Public doctors feed contract (`GET /api/public/doctors`)

```json
{ "doctors": [ { "id": "...", "name": "...", "specialty": "..." | null,
   "photoUrl": "..." | null, "status": "ON_DUTY_NOW" | "ON_LEAVE" | "NOT_ON_DUTY",
   "todayShifts": [ { "start": "HH:mm", "end": "HH:mm" } ],
   "nextShiftStartsAt": "ISO|null" } ], "generatedAt": "ISO" }
```

Headers: `Cache-Control: public, max-age=60, stale-while-revalidate=120`.
Unauthenticated; excluded from the session gate by the proxy matcher
(`src/proxy.ts` skips `/api`). Dashboard variant `getOnDutyDoctors()` uses the
same projection but does **not** filter `showOnWebsite`.

---

## 8. i18n keys used (complete list)

### `hr.*` — complete namespace from `messages/en.json`

Titles/labels: `scheduleTitle`, `leaveTitle`, `leaveInboxTitle`,
`leaveCalendarTitle`, `staffLabel`, `selectStaff`, `date`, `startTime`,
`endTime`, `note`, `leaveType`, `startDate`, `endDate`, `days`, `reason`,
`decide`, `decisionNote`, `week`*, `today`.

Schedule: `overnightHint`, `addShift`, `editShift`*, `deleteShiftConfirm`,
`noStaff`, `noShifts`*, `templates`, `allStaff`, `from`, `to`,
`previewTemplate`, `applyTemplate`, `previewResult`
(`{count}`, `{skipped}`), `copyLastWeek`, `copyLastWeekHint`.

Leave: `requestLeave`, `submitRequest`, `halfDay`, `halfDayHint`, `balance`,
`balanceEntitlement`, `balanceTaken`, `balanceRemaining`, `balanceUnset`,
`balanceHint`, `myLeave`*, `noLeave`, `statusRequested`, `statusApproved`,
`statusRejected`, `statusCancelled`, `approve`, `reject`, `cancelRequest`,
`cancelConfirm`, `inboxEmpty`, `conflictAppointments` (`{count}`).

Duty widget: `onDutyTitle`, `onDutyNow`, `onLeave`, `notOnDuty`,
`noShiftsToday`.

Leave types: `typeAnnual`, `typeSick`, `typeUnpaid`, `typeOther`.

Weekdays: `daySun`, `dayMon`, `dayTue`, `dayWed`, `dayThu`, `dayFri`, `daySat`.

Action errors (rendered via `HrError` under `hr.`): `shiftOverlap`,
`leaveOverlap`, `cannotDecideOwnLeave`, `leaveNotPending`, `cannotCancel`,
`staffRequired`.

(*) `week`, `noShifts`, `myLeave`, `editShift` exist in the namespace but have
**no caller** in legacy code — carry them only for completeness.

### `validation.*` keys referenced by HR schemas

`required`, `tooLong`, `invalid`, `invalidDate`, `invalidTime`,
`invalidLeaveType`, `endBeforeStart`, `rangeTooLong`. (Field paths used:
`id`, `staffId`, `dateKey`, `startTime`, `endTime`, `note`, `weekday`,
`validFrom`, `validTo`, `fromKey`, `toKey`, `type`, `startDate`, `endDate`,
`reason`, `leaveId`.)

### `common.*` keys referenced by HR code

`loading`, `saving`, `save`, `cancel`, `close`, `edit`, `delete`, `status`,
`actions`, `optional`, `previous`, `next`, `forbidden`, `unknownError`,
`validationError`, `passwordChangeRequired`.

### Other namespaces touched by HR surfaces

- `nav.*`: `hrm`, `staff`, `users`, `leave`, `leaveInbox`, `leaveCalendar`,
  `schedule`.
- `dashboard.*` (quick links + stats): `schedule`, `leave`, `leaveInbox`,
  `leaveCalendar`, `statPendingLeave`, `statMyShiftsWeek`, `statMyLeaveRemaining`.
- `export.*`: `formatCsv`, `formatXlsx`.
- `site.doctors.*` on the public doctors page (public module, listed for context).

English strings for the exact error text live in `messages/en.json`; the AM
locale (`messages/am.json`) mirrors the same keys.

---

## 9. Test inventory (Vitest → PHPUnit)

### 9.1 `src/modules/hr/shifts.test.ts` — `TZ = 'Africa/Addis_Ababa'`

`describe('shift geometry')`

| # | test name | assertions |
|---|---|---|
| 1 | `detects overnight templates (end <= start)` | `isOvernight('22:00','06:00')===true`; `('06:00','22:00')===false`; `('09:00','09:00')===true` |
| 2 | `places an overnight shift on the next day and spans 8h` | `shiftInstantsForDay({startTime:'22:00',endTime:'06:00'},'2026-10-08',TZ)`: `startsAt === at('2026-10-08','22:00')`, `endsAt === at('2026-10-09','06:00')`, `endsAt-startsAt === 8*3600*1000` |
| 3 | `keeps a same-day shift on the same day` | `09:00→17:00` on `2026-10-08` spans exactly `8*3600*1000` ms |
| 4 | `overlap is symmetric and half-open (touching edges do not overlap)` | `a=[09,12)`,`b=[12,15)`,`c=[11,13)`: `a↔b false`, `a↔c true`, `c↔a true` |
| 5 | `finds an overlapping shift for the same person only` | existing `s1(p1,09–17)`,`s2(p2,09–17)`; candidate `p1 16–20` → `s1`; candidate `p3 16–20` → `undefined` |
| 6 | `ignores the shift being edited (ignoreId)` | candidate `p1 10–18` with `ignoreId:'s1'` → `undefined` |
| 7 | `detects active and next shifts` | active at `10:00` → true; at `17:00` → false; `nextShiftAfter([s1,s2], at 10)` equals `s2.startsAt`; `nextShiftAfter([s1], at 18:00)` → `null` |

`describe('template generation')` — fixtures `t1 = p1, weekday=weekdayOf('2026-10-08'), 09:00–17:00, validFrom '2026-10-01', validTo null`; `t2` same weekday `20:00–04:00`.

| # | test name | assertions |
|---|---|---|
| 8 | `generates matching weekdays across the range, including overnight` | `generateTemplateShifts(ts,'2026-10-08','2026-10-21',TZ)` has length `4`; the `t2` row starts `at('2026-10-08','20:00')` and ends `at('2026-10-09','04:00')` |
| 9 | `respects validFrom/validTo windows` | `t1` with `validFrom '2026-10-10'`, `validTo '2026-10-20'` over `2026-10-08..2026-10-21` → length `1`, `dateKey === '2026-10-15'` |
| 10 | `builds inclusive day lists and Monday-based weeks` | `dateKeysBetween('2026-10-08','2026-10-10') === ['2026-10-08','2026-10-09','2026-10-10']`; `weekStartKey('2026-10-08') === '2026-10-05'`; `weekDays('2026-10-05').length === 7`; `weekDays(...)[6] === '2026-10-11'` |
| 11 | `shiftByDays advances an overnight shift by 7 days` | `2026-10-08 22:00 → 2026-10-15 22:00` and `2026-10-09 06:00 → 2026-10-16 06:00` |

### 9.2 `src/modules/hr/leave.test.ts`

Fixture `leave(partial)` defaults: `staffId 'p1'`, `type 'ANNUAL'`,
`status 'APPROVED'`, `startDate = endDate = '2026-10-08'`, `halfDay false`.

`describe('leave geometry')`

| # | test name | assertions |
|---|---|---|
| 1 | `counts inclusive calendar days` | `countCalendarDays('2026-10-08','2026-10-08')===1`; `('2026-10-08','2026-10-10')===3` |
| 2 | `half days count 0.5` | same-day `halfDay:true → 0.5`; `halfDay:false → 1` |
| 3 | `detects overlapping ranges (inclusive)` | `(08..10, 10..12) → true`; `(08..10, 11..12) → false` |
| 4 | `rejects a request overlapping an existing approved leave for the same person` | existing `l1 2026-10-08..2026-10-12`; candidate `p1 10-11..10-15 → 'l1'`; candidate `p2` same range → `undefined` |
| 5 | `only APPROVED leave covers a date (suppresses duty)` | approved `10-08..10-10`: covers `10-09 → true`, `10-11 → false`; pending `REQUESTED 10-08`: covers `10-08 → false`; `isOnLeaveAt([approved],'p1','2026-10-09') → true`; `…'p2'… → false` |
| 6 | `computes annual balance for the year (half days included)` | leaves: `a 03-02..03-04 (3)`, `b 06-01..06-01 halfDay (0.5)`, `c type SICK (ignored)`, `d 2025-12-30..31 (other year)`, `e status REQUESTED (ignored)`; `leaveBalance(20, leaves, 2026)` → `taken === 3.5`, `remaining === 16.5` |
| 7 | `returns no balance when entitlement is unset` | `leaveBalance(null, [], 2026)` → `entitlement === null`, `remaining === null`, `taken === 0` |

### 9.3 `src/modules/hr/public-status.test.ts` — `TZ = 'Africa/Addis_Ababa'`

Fixture `doctor`: `{id:'doc-1', fullName:'Dr. Abebe Kebede', specialty:'Ophthalmology', photoUrl:'/api/uploads/doc-1.png', isDoctor:true, showOnWebsite:true, active:true}`; `now = at('2026-10-08','10:00')`.

| # | test name | assertions |
|---|---|---|
| 1 | `publishes only flagged, active doctors` | `isPublicDoctor(doctor) === true`; `active:false → false`; `showOnWebsite:false → false`; `isDoctor:false → false` |
| 2 | `reports ON_DUTY_NOW during a running shift` | shift `09:00–17:00` today → `status 'ON_DUTY_NOW'`; `todayShifts === [{start:'09:00',end:'17:00'}]`; `nextShiftStartsAt === at('2026-10-08','09:00').toISOString()` |
| 3 | `approved leave wins and marks the doctor ON_LEAVE even during a shift` | same running shift + `APPROVED ANNUAL 2026-10-08..2026-10-08` → `status 'ON_LEAVE'` |
| 4 | `reports NOT_ON_DUTY with the next shift when none is running` | only shift on `2026-10-09` → `status 'NOT_ON_DUTY'`, `todayShifts === []`, `nextShiftStartsAt === at('2026-10-09','09:00').toISOString()` |
| 5 | `never leaks private or internal fields` | input staff carries `phone '+251911223344'`, `email 'doctor@visioneye.et'`, `annualLeaveDays 20`, `jobTitle`, `createdAt`, `secret:'internal'` + an `APPROVED SICK` leave; `JSON.stringify(status)` contains **none** of `'phone'`, `'+251911223344'`, `'email'`, `'doctor@visioneye.et'`, `'annualLeaveDays'`, `'jobTitle'`, `'secret'`, `'internal'`; `Object.keys(status).sort() === ['id','name','nextShiftStartsAt','photoUrl','specialty','status','todayShifts']` |

### 9.4 Cross-module (adjacent, same contract)

`src/modules/public-site/projection.test.ts` → `the public doctor feed never
contains a forbidden field`: builds a doctor with a running shift via
`buildPublicDoctorStatus`, asserts `Object.keys(doctor).sort() ===
[...PUBLIC_DOCTOR_FIELDS].sort()` and `findForbiddenFields(doctor).size === 0`.

### 9.5 E2E / smoke (map to Laravel feature tests)

- `e2e/leave.spec.ts` → `staff requests and owner approves`:
  STAFF opens `/leave`, selects `#leave-type = ANNUAL`, fills `#leave-start` /
  `#leave-end` (start = today+14, end = start+2), `#leave-reason`, clicks
  "Submit request", expects the reason text visible (≤15 s); then OWNER opens
  `/leave/inbox`, sees the reason, clicks `Approve` in the matching row, and the
  Approve button disappears from that row.
- `scripts/smoke.sh` §11b: owner `/schedule` 200 + contains "Add shift" and a
  seeded doctor name; owner `/leave` 200 + "Annual leave balance";
  owner `/leave/inbox` 200 + seeded requester + "Approve";
  owner `/leave/calendar` 200 + seeded doctor; reception `/schedule` 200 with
  team rows and **no** `template-staff` element; staff `/schedule` 200 with own
  rows and **no** teammate name; staff `/leave/inbox` contains
  `NEXT_REDIRECT;replace;/403`; public feed 200 with `"doctors"`, exactly 3
  `"specialty"` occurrences (seed), no `annualLeaveDays|jobTitle|"email"|"phone"`,
  and a response header containing `max-age=60`.

---

## 10. UI behavior per page

### `/schedule` — weekly grid

- Header `hr.scheduleTitle`; OWNER-only primary button `hr.addShift` opens
  `ShiftFormModal` (create) with `defaultDateKey = startKey`.
- Pager: `common.previous` → `?week=<startKey−7>`, `hr.today` → `/schedule`,
  `common.next` → `?week=<startKey+7>`, plus a plain range caption
  `startKey — startKey+6`.
- Table: first column `hr.staffLabel`, then 7 day columns labelled with
  `hr.daySun…daySat` from the key's weekday plus the `MM-DD` slice in muted
  text. Empty staff list → a single row spanning 8 columns with `hr.noStaff`.
- Rows: **OWNER/RECEPTION** = every entry from `listStaffOptions()`;
  **STAFF** = only their own linked staff row (no row when the account has no
  `staffId`).
- Cell: one bordered chip per shift showing `start–end`, a muted `+1` when the
  shift ends on a later clinic-local day, the `note` underneath, and (OWNER
  only) two link-styled controls: `common.edit` (opens `ShiftFormModal` in edit
  mode prefilled with `{id, staffId, dateKey, startTime, endTime, note}`) and
  `common.delete` (`DeleteShiftButton`, red text link). Empty cell → muted `—`.
- Both create and edit submit to the modal's action; on `ok` the router refreshes
  and the dialog closes; on failure per-field `validation.*` messages show under
  the inputs and `HrError` shows the action-level message.
- Delete posts `id` after `window.confirm(hr.deleteShiftConfirm)`.
- Cell/week chip colours: the grid does **not** colour by staff (the 10-colour
  `SHIFT_COLORS` palette + `colorForIndex` are unused by the legacy UI).
- **Template panel** (OWNER only, `hr.templates` card):
  - Row 1 form: staff `<select>` (`hr.staffLabel`, default `hr.allStaff` =
    empty value), `fromKey` date (`hr.from`, default `weekStart`), `toKey` date
    (`hr.to`, default `weekStart`), a secondary **Preview** button bound to the
    preview action (label `hr.previewTemplate`, `common.loading` while
    pending) and a primary **Apply** button (`hr.applyTemplate`,
    `common.saving` while pending). Both buttons disabled while either is
    pending.
  - Preview result line: `hr.previewResult` with `{count}` = rows to create and
    `{skipped}` = overlaps skipped. Field errors surface on `toKey`
    (`rangeTooLong` etc.); other failures via `HrError`.
  - Row 2 form (border-top): hidden `weekStart`, secondary button
    `hr.copyLastWeek` (`common.saving` while pending) + hint
    `hr.copyLastWeekHint`.
  - After a successful **apply** or **copy**, the router refreshes.

### `/leave` — my leave + balance

- Title `hr.leaveTitle`; `ExportLinks` (CSV + XLSX → `/api/leave/export?format=…`).
- Two-column grid (`lg:grid-cols-2`):
  - **Request form** (`hr.requestLeave`) when the user has a staff link **or**
    is OWNER: staff picker (`hr.staffLabel` + `hr.selectStaff`, default = own
    staff) rendered **only for OWNER**; type `<select>` default `ANNUAL`
    labelled `hr.leaveType`; `startDate`/`endDate` date inputs both defaulting
    to today's clinic-local key; `halfDay` checkbox with hint
    `hr.halfDayHint` ("counts as 0.5 days"); `reason` textarea `maxLength=300`;
    submit `hr.submitRequest` (`common.saving` while pending). Otherwise the
    card shows `hr.noStaff`.
  - **Balance card** (`hr.balance`): 3-column dl with `hr.balanceEntitlement`,
    `hr.balanceTaken`, `hr.balanceRemaining` when `entitlement !== null`;
    otherwise `hr.balanceUnset`; footnote `hr.balanceHint` ("Counts approved
    annual leave for the current year only.").
- Own requests table, columns: `hr.leaveType`, `hr.startDate`, `hr.endDate`,
  `hr.days` (computed `requestedDays`, may be `0.5`), `common.status` (pill:
    APPROVED → `bg-emerald-50 text-emerald-700`, REJECTED → `bg-red-50 text-red-700`,
    CANCELLED → `bg-slate-100 text-slate-500`, REQUESTED → `bg-amber-50 text-amber-700`),
    `hr.decisionNote` (or `—`), `common.actions`. Empty → row with `hr.noLeave`.
- Actions cell: `CancelLeaveButton` when
  `canCancelLeave({role, isSelf, status})`, else muted `—`. Cancel posts
  `leaveId` after `window.confirm(hr.cancelConfirm)`, label `hr.cancelRequest`
  / `common.saving` while pending; errors via `HrError`.

### `/leave/inbox` — decision form (OWNER)

- Title `hr.leaveInboxTitle`. Table columns: `hr.staffLabel`, `hr.leaveType`,
  `hr.startDate`, `hr.endDate`, `hr.days`, `hr.reason`, `hr.decide`.
  Empty → row with `hr.inboxEmpty`.
- Reason cell: `reason ?? '—'`; when `appointmentConflicts > 0` an amber line
  `hr.conflictAppointments` with `{count}`.
- Decision cell (`LeaveDecisionForm`): hidden `leaveId`, a text input
  (`w-48`, `maxLength=300`, placeholder + aria-label `hr.decisionNote`) bound to
  hidden `note`, a primary submit `name="decision" value="approve"` labelled
  `hr.approve`, a secondary submit `name="decision" value="reject"` labelled
  `hr.reject`, both disabled while pending, plus `common.optional` microcopy.
  The dispatcher reads `decision === 'reject'` to route to the reject action;
  on `ok` the router refreshes (row disappears). Errors via `HrError`.

### `/leave/calendar` — 30-day team table (OWNER + RECEPTION)

- Title `hr.leaveCalendarTitle`. Pager: `common.previous` (`?from=<from−30>`),
  `hr.today` (`/leave/calendar`), `common.next` (`?from=<from+30>`), caption
  `fromKey — toKey` where `toKey = fromKey + 29`.
- Columns: `hr.staffLabel`, `hr.leaveType`, `hr.startDate`, `hr.endDate`,
  `hr.days`, `common.status` (same pill classes). Empty → `hr.noLeave`.
  STAFF never reaches this page (`/403`).

### `/dashboard` — on-duty widget (context)

- Section heading `hr.onDutyTitle`; per doctor: name, specialty, today's shifts
  joined as `HH:mm–HH:mm` (else `hr.noShiftsToday`), and a status pill from
  `hr.onDutyNow` / `hr.onLeave` / `hr.notOnDuty` with matching tint
  (`emerald` / `amber` / `slate`). Data from `getOnDutyDoctors()` (no
  `showOnWebsite` filter).

### Public pages (context)

- `/api/public/doctors` JSON per §7; `/doctors` renders `getPublicDoctorStatus()`
  with avatars, specialty, `DoctorStatusBadge` (uses `site.doctors.*`) and the
  today-shift list as `HH:mm–HH:mm`. Home page shows up to 4, preferring
  `ON_DUTY_NOW` when any doctor is on duty.

---

## Ambiguities resolved (for the Laravel build)

1. **README "shift range windows / half-day hours" in `constants.ts`** — the
   legacy file contains no shift windows and no hour tables. Ported as: enum
   lists, label-key maps, 10-colour palette, five numeric caps, two regexes.
   Half-day = `0.5` days, not hours.
2. **README checklist 27 "range-too-long … leave"** vs code — `MAX_RANGE_DAYS
   (62)` is enforced only by `generateFromTemplateSchema`. Leave requests have
   **no** length cap. Spec follows the code (rule 14); a Laravel cap would be a
   new behaviour.
3. **README checklist 28 "approving more days than the balance is blocked"** —
   no such check exists in `approveLeaveAction` (only `status === REQUESTED`
   and not-self). Spec follows the code (rule 20/22); flag if you add it.
4. **README checklist 29 "…and the SMART template days"** — the legacy calendar
   renders leave rows only; no "SMART" token exists anywhere in the repo.
   Treated as unimplemented/aspirational.
5. **"highest-role peer decides"** — implemented purely as "actor's `staffId`
   ≠ request's `staffId`"; there is no explicit peer/hierarchy algorithm. OWNER
   accounts without a staff link always pass.
6. **Template CRUD** — `createTemplateAction` / `deleteTemplateAction` exist and
   audit, but no legacy page invokes them (the panel only previews/applies/copies).
   Laravel may expose them or drop them; audits are specified either way.
7. **`SHIFT_COLORS` / `colorForIndex`** — exported and documented as "grid
   colours" but unused by the legacy grid. Not required for parity.
8. **Appointment-hint window** uses UTC-midnight `[start, end)` bounds for the
   leave range, so the final day's appointments are excluded (rule 21). Preserved
   as-is; noted because it reads as a bug.
9. **`CANCELLED` note** is stored only in the audit diff, not on
   `LeaveRequest.decisionNote`.
