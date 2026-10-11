# Vision Eye Clinic ERP

Single-tenant clinic ERP for Vision Eye Clinic: a bilingual public website
(marketing pages, booking, live "on duty" doctors, CMS-lite, SEO) plus lite
clinic management (users, staff, settings, audit trail, patients, services,
sales/POS + revenue, HR lite (shifts/schedule, leave approvals, a cached
public "on-duty now" feed) and appointments (day/week/list views, website
booking requests → converted appointments, and patient recall follow-ups).
Reachable **only** via the secret link `/login/<LOGIN_SECRET_PATH>`.

## Stack

- Next.js 16 (App Router, TypeScript, Turbopack), Tailwind CSS 4
- PostgreSQL + Prisma (`prisma/schema.prisma`)
- Auth.js (NextAuth v5) credentials — **email + argon2 password**, JWT session (12h)
- next-intl (en / am — every UI string is translated)
- Vitest (unit tests), ESLint + Prettier

## Quick start

```bash
npm install
npm run db:up          # embedded local Postgres on :5433 (or point DATABASE_URL elsewhere)
cp .env.example .env   # then fill AUTH_SECRET, LOGIN_SECRET_PATH, seed passwords
npm run db:migrate     # apply prisma/migrations
npm run db:seed        # settings + staff + users + tags + patients + services + sales + HR shifts/leave + appointments/requests/follow-ups (idempotent)
npm run dev            # http://localhost:3000
```

Login URL: `http://localhost:3000/login/<LOGIN_SECRET_PATH>` — any other
`/login/...` path returns a real 404 (checked in proxy + page).

Seeded accounts (passwords come from `.env`):

| Role      | Email (default)          | First login                 |
| --------- | ------------------------ | --------------------------- |
| OWNER     | `owner@visioneye.et`     | already changed in dev seed |
| RECEPTION | `reception@visioneye.et` | forced password change      |
| STAFF     | `doctor@visioneye.et`    | forced password change      |

## Commands

```bash
npm run dev            # dev server
npm run build && npm start   # production
npm run lint           # ESLint
npm run typecheck      # tsc --noEmit
npm run messages       # en/am catalogs identical + all t() keys resolve
npm test               # Vitest unit tests
npm run test:e2e       # Playwright specs (npx playwright install once; seeded DB + server)
npm run db:migrate     # create/apply migrations (dev)
npm run db:reset       # drop + migrate + seed
npm run db:load        # generate a staged load dataset (5k patients, 20k sales)
npm run backup         # pg_dump to backups/ (needs PostgreSQL client tools)
npm run restore -- <dump>  # restore (FORCE=1 to replace)
npm run export:client -- <dir>  # CSV copy of patients + sales for the clinic
npm run security:scan  # regenerate docs/security-review.md (guards + roles)
npm run audit:deps     # fail on high/critical dependency advisories
bash scripts/smoke.sh  # production smoke test, 173 checks (server must be running)
```

## Documentation

- `docs/roles.md` — what OWNER, RECEPTION and STAFF can do.
- `docs/messaging-6b-manual.md` — richer filters, drafts, Message Library and
  the One-group-chat delivery flow.
- `docs/deployment.md` — production install, proxy, jobs, upgrades.
- `docs/go-live.md` — pre-launch checklist (critical / content / people).
- `docs/uat.md` — user acceptance test script + sign-off table.
- `docs/troubleshooting.md` — common problems and fixes.
- `docs/backup-restore.md` — backups, restores, client data export.
- `docs/performance.md` — pagination caps, indexes, load testing.
- `docs/security-review.md` — generated endpoint/guard + role matrix.
- `docs/known-gaps.md` — deliberate limitations and seams.

## Project structure

```
erp/
├── prisma/
│   ├── schema.prisma          # User, Staff, Setting, AuditLog, Patient, Tag,
│   │                          # Service, ServicePriceHistory, Sale, SaleLine, ReceiptCounter,
│   │                          # ShiftTemplate, Shift, LeaveRequest (+ LeaveType/LeaveStatus/ShiftSource),
│   │                          # Appointment, AppointmentRequest, FollowUp (+ status/source enums),
│   │                          # MessageTemplate, MessageTask, Campaign (+ status/source enums)
│   ├── migrations/            # init + timestamptz + patients + services_sales + hr_lite + appointments + messaging
│   ├── seed.ts                # settings, staff, users, tags, patients, services, sales, HR + appointments + messages seed
│   ├── seed-patients.ts       # 20 Ethiopian patients, mixed phone formats (idempotent)
│   ├── seed-services.ts       # 12 services + 40 sales over 60 days (deterministic, idempotent)
│   ├── seed-hr.ts             # 6 staff + 4 templates + 72 shifts + 2 leave requests (deterministic, idempotent)
│   ├── seed-appointments.ts   # 20 appointments + 5 website requests + 5 follow-ups (deterministic, idempotent)
│   ├── seed-messages.ts       # 13 template keys × en/am (26 rows, idempotent upsert)
│   ├── seed-library.ts        # 10 library messages × en/am + 2 saved audiences
│   ├── seed-group-e2e.ts      # deterministic 40-patient fixture for the group E2E
├── scripts/
│   ├── db.mjs                 # embedded Postgres lifecycle
│   ├── messages-check.mjs     # i18n completeness gate
│   ├── backup.sh              # pg_dump custom-format + retention
│   ├── restore.sh             # pg_restore (FORCE=1 replaces)
│   ├── client-data-export.ts  # CSV copy of patients + sales
│   ├── security-scan.mjs      # → docs/security-review.md
│   ├── audit-deps.sh          # npm audit (high+)
│   └── smoke.sh               # production smoke test (173 checks)
├── e2e/                       # Playwright specs (smoke, roles, patient→sale,
│                              # request→appointment, leave, messages)
├── messages/en.json, am.json  # full translations (identical structure)
└── src/
    ├── proxy.ts               # secret-link gate + locale cookie (edge)
    ├── auth.ts                # Auth.js config (credentials, JWT, cookie flags)
    ├── env.ts                 # zod-validated env, fail-fast at startup
    ├── instrumentation.ts     # imports env on server boot
    ├── components/            # AppShell (sidebar/drawer/user menu + GlobalSearch),
    │                          # RoleGate, ActionError, ExportLinks, PrintPageButton,
    │                          # LocaleSwitcher, AutoSubmitSelect (client filter island)
    ├── lib/                   # require-role, action envelope, audit(+diff/redact),
    │                          # passwords, login-policy (lockout), ratelimit, ip (hashClientIp),
    │                          # storage (StorageProvider), csv, xlsx (dependency-free writer),
    │                          # export (csv/xlsx + audit), money, phone, datetime
    ├── modules/
    │   ├── auth/              # login form, change-password, verify-credentials
    │   ├── dashboard/         # role home widgets, daily summary + print,
    │   │                      # optional daily summary email (OWNER)
    │   ├── users/             # CRUD + activate/reset (OWNER only)
    │   ├── staff/             # CRUD + photo upload + activate (OWNER only)
    │   ├── settings/          # 15 keys, validated form (OWNER only)
    │   ├── audit/             # filtered viewer + CSV export (OWNER only)
    │   ├── patients/          # list/form/detail/import logic + components
    │   │   ├── actions.ts     # create/update (dup guard), tags, stop-messages, import
    │   │   ├── queries.ts     # list (search/filters/sort/page), detail, duplicates
    │   │   ├── import.ts      # CSV template/parse/validate (1000-row cap)
    │   │   ├── schemas.ts     # zod patient form schema (i18n messages)
    │   │   ├── tags.ts        # tag list + deterministic tag colors
    │   │   ├── tabs.ts        # detail tabs + loaders (sales tab → listPatientSales)
    │   │   ├── roles.ts       # PATIENT_ROLES = OWNER, RECEPTION
    │   │   ├── duplicates.ts  # phone match (blocks) + name/birthDate near-match
    │   │   ├── consent.ts     # consent/stop-messages helpers (audit-friendly)
    │   │   └── components/    # PatientForm, TagsEditor, StopMessagesCard, ImportForm, …
    │   ├── services/          # service catalog + price history (OWNER only)
    │   │   ├── categories.ts  # fixed category keys + label-key map
    │   │   ├── actions.ts     # create/update (+ price-history row), activate/deactivate
    │   │   ├── queries.ts     # list/options/detail/price history
    │   │   └── components/    # ServiceForm, ServiceStatusButton, PriceHistoryModal
    │   ├── sales/             # POS + receipts + revenue reporting
    │   │   ├── actions.ts     # create/confirm, request/approve/reject void, patient search
    │   │   ├── receipt.ts     # gap-free per-year receipt numbers (atomic upsert)
    │   │   ├── lines.ts       # server-side price snapshots (pure, tested)
    │   │   ├── revenue.ts     # period presets + aggregation (pure, tested)
    │   │   ├── queries.ts     # list/detail/export/void inbox/revenue source
    │   │   ├── roles.ts       # revenue & void approval = OWNER only
    │   │   └── components/    # NewSaleForm, ReceiptView, PrintButtons, ReasonActionButton, PatientSalesList
    │   ├── hr/                # HR lite: shifts/schedule + leave
    │   │   ├── constants.ts   # shift range windows, half-day hours
    │   │   ├── shifts.ts      # template generation, overlap, week grid (pure, tested)
    │   │   ├── leave.ts       # balances, half days, overlap, duty suppression (pure, tested)
    │   │   ├── public-status.ts # website-facing doctor status (pure, tested, leak-proof)
    │   │   ├── roles.ts       # HR_OWNER_ROLES / HR_TEAM_LEAVE_ROLES / HR_SELF_ROLES
    │   │   ├── schemas.ts     # zod form schemas (shift/leave, i18n messages)
    │   │   ├── queries.ts     # schedule/leave/team-calendar/public queries
    │   │   ├── actions.ts     # create/update/delete shift, apply template, request/decide/cancel leave
    │   │   ├── index.ts       # barrel
    │   │   └── components/    # ShiftFormModal, TemplatePanel, DeleteShiftButton,
    │   │                      # LeaveRequestForm, LeaveDecisionForm, CancelLeaveButton, HrError
    │   ├── appointments/      # appointments + website requests + follow-ups
    │   │   ├── constants.ts   # status/source enums, date + phone regexes, wait-minutes
    │   │   ├── roles.ts       # APPT/REQUEST/FOLLOW_UP_ROLES (OWNER, RECEPTION)
    │   │   ├── logic.ts       # status transitions, conflict/double-book + working minutes (pure, tested)
    │   │   ├── schemas.ts     # zod form + public-request schemas (i18n messages)
    │   │   ├── queries.ts     # list/detail/counts + follow-up + request queries
    │   │   ├── actions.ts     # create/update/transition, convert request, create follow-up
    │   │   └── components/    # AppointmentFormModal, AppointmentRowButtons, RequestRowActions,
    │   │                      # ConvertRequestDialog, FollowUpRowActions, RequestAge, …
    │   └── messaging/         # manual-link SMS outbox (no gateway): templates, tasks,
    │       │                  # campaigns, opt-out, digest; SmsProvider seam (GatewayProvider stub)
    │       ├── constants.ts   # template/status/source/blocked-reason key maps, language rules
    │       ├── templates.ts   # built-in en/am bodies + variable registry + message-body validation
    │       ├── provider.ts    # SmsProvider: ManualLinkProvider + GatewayProvider (throws)
    │       ├── render.ts      # variable substitution + GSM-7/Unicode segment counting (pure, tested)
    │       ├── schedule.ts    # task timing rules in clinic time (pure, tested)
    │       ├── eligibility.ts # active + consent + not-opted-out + valid phone (pure, tested)
    │       ├── campaign-filter.ts # audience matcher + exclusion counts (pure, tested)
    │       ├── recipient-filter.ts # 6B richer filter + group-safe rules (pure, tested)
    │       ├── group-plan.ts  # batch splitting, e.g. 40 → [15,15,10] (pure, tested)
    │       ├── message-hash.ts # library duplicate guard (pure, tested)
    │       ├── recipients.ts  # server-side resolveRecipients + patient type-ahead
    │       ├── generate.ts    # idempotent task generation + expiry + cancelPending*
    │       ├── campaign.ts    # campaign loader/preview/materialize (server)
    │       ├── actions.ts     # open/confirm/postpone/skip/edit, jobs, templates, campaigns,
    │       │                  # drafts, library, audiences, batch handling, patient search
    │       ├── queries.ts     # outbox groups, counts, templates, campaigns, batches, library,
    │       │                  # drafts, audiences, expired report
    │       ├── roles.ts       # MESSAGE_ROLES, MESSAGE_OWNER_ROLES, library/audience/override rules
    │       ├── schemas.ts     # zod form schemas (i18n messages)
    │       └── components/    # OutboxBoard, PatientMessagesList, TemplateEditor,
    │                          # MessageWizard (replaces CampaignBuilder), CampaignQueue,
    │                          # LibraryBoard, DraftsBoard, RunJobsButton, MessagesTicker
    │   └── public-site/       # public website: content (CMS-lite keys/defaults),
    │       │                  # queries (cached, whitelisted), projection (allow-list
    │       │                  # + leak scanner, tested), schemas (booking), actions
    │       │                  # (saveSiteContent OWNER-only), seo (metadata/hreflang),
    │       │                  # format, turnstile (optional), components (PublicHeader/
    │       │                  # Footer/LocaleSwitcher, BookingForm, DoctorAvatar/Badge,
    │       │                  # AnnouncementBanner, SiteContentForm, JsonLd, Analytics)
    └── app/
        ├── (public)/          # open marketing site: / about services doctors book
        │                      # contact faq privacy (+ not-found, opengraph-image)
        ├── sitemap.ts, robots.ts
        ├── login/[secret]/    # the only entry point
        ├── (app)/             # / 403 change-password users staff settings audit
        │   ├── patients/      # list, new, [id] (+ tabs), [id]/edit, import
        │   ├── dashboard/     # home, services (list/new/[id]/edit, OWNER), content (CMS)
        │   ├── sales/         # list, new, [id] (receipt + void actions), voids
        │   ├── revenue/       # OWNER-only revenue dashboard
        │   ├── schedule/      # weekly shift grid (OWNER manage, RECEPTION team view, STAFF self)
        │   ├── leave/         # my leave + balance, inbox (OWNER), team calendar
        │   ├── appointments/  # day/week/list views + create/edit/transition (OWNER/RECEPTION)
        │   ├── requests/      # website booking inbox, filter by status, convert to appointment
        │   ├── follow-ups/    # open/overdue/done/cancelled recall list (OWNER/RECEPTION)
        │   └── messages/      # outbox, templates, campaigns (+ new/[id]), reports (OWNER/RECEPTION)
        └── api/               # auth callback, uploads, audit export,
                                # patients export + CSV template, sales export,
                                # appointments/messages/leave export, global search,
                                # public doctors, public appointment requests (POST,
                                # rate-limited, ipHash only), messages/count (badge poll),
                                # jobs/messages (cron, bearer secret)
```

## Security model

- **Secret link**: `proxy.ts` 404s `/login/*` unless the path matches
  `LOGIN_SECRET_PATH`; the page re-checks server-side.
- **Session**: JWT, httpOnly + sameSite=lax + secure(prod), 12h expiry.
- **Password**: argon2id, min 10 chars, common/personal-password rejection,
  15-min lockout after 5 failed attempts, per-IP rate limit (30/5 min),
  every attempt audited (`LOGIN_FAILED`/`LOGIN_SUCCESS`).
- **Forced first login**: `mustChangePassword` — pages redirect to
  `/change-password`, server actions refuse with `passwordChangeRequired`
  until the password changes (JWT claim refreshed by auto re-login).
- **Authorization**: `requireRole()` for actions, `requirePageRole()` for pages
  (wrong role → `/403`), `<RoleGate>` for UI-only hiding. OWNER-only menus.
- **HR**: OWNER manages shifts/templates and decides leave; RECEPTION sees the
  team schedule + team leave calendar but has no edit controls; STAFF sees only
  their own schedule and requests their own leave. Nobody can approve/reject
  their own leave (highest-role peer decides) and approved leave suppresses duty
  with an "On leave" status. Mutations audit `SHIFT_CREATED/…`, `LEAVE_*`.
- **Public on-duty feed**: `GET /api/public/doctors` requires no session and is
  cached 60 s (`stale-while-revalidate=120`). It is built from a strict allow-list
  (active published doctors only) and returns only `id/name/specialty/photoUrl/
status/todayShifts/nextShiftStartsAt` — no phone, email, internal fields,
  salary/leave data, or leave reasons.
- **Public booking intake**: `POST /api/public/requests` needs no session and is
  rate-limited per hashed IP (429), validates the same zod rules as internal
  forms (400 invalid JSON / 422 field errors) and stores only a sha-256 IP hash
  (never the raw IP). Responses are `Cache-Control: no-store`.
- **Appointments**: OWNER and RECEPTION only. Status transitions follow a fixed
  graph (`SCHEDULED→CONFIRMED/CANCELLED/NO_SHOW`, `CONFIRMED→COMPLETED/CANCELLED/
NO_SHOW`); terminal states are frozen unless an OWNER overrides, and every
  change is audited. Double-booking/outside-shift/on-leave are non-blocking
  warnings (the OWNER can override + the override is audited). Converting a
  website request matches/creates a patient and links the new appointment.
- **Audit**: every mutation writes `AuditLog` with a field-level diff;
  `passwordHash` and other secrets are always `[REDACTED]`. Opening a patient
  detail page is logged as `VIEW` (one row per user per minute — the page body
  runs twice per request and serializes on a Postgres advisory lock). Sales
  audit `SALE_CREATED`, `SALE_VOID_REQUESTED`, `SALE_VOID_APPROVED`,
  `SALE_VOID_REQUEST_REJECTED`, `SALE_VOIDED`; services audit price changes.
- **Headers**: CSP (dev-aware), X-Frame-Options DENY, nosniff, Referrer-Policy,
  Permissions-Policy, HSTS (prod) via `next.config.ts headers()`.
- **Uploads**: PNG/JPEG/WebP ≤ 2 MB behind a `StorageProvider`, served only to
  signed-in users, path-traversal-safe keys.

## Money / time / phone conventions (later slices)

- Money: Prisma `Decimal` `NUMERIC(12,2)` — never floats (`src/lib/money.ts`).
- Times: stored UTC (`@db.Timestamptz`), displayed in the clinic timezone
  (`Africa/Addis_Ababa`, configurable in Settings) — `src/lib/datetime.ts`.
  Shift days are clinic-date keys in the clinic timezone (`YYYY-MM-DD`) and
  a shift may cross midnight (`start > end`).
- HR leave balance: annual days from `Staff.annualLeaveDays`; whole or half days
  only; overlap with an existing approved leave is rejected; requests cannot
  modify/cancel an already-approved leave except by an OWNER cancel.
- Phones: normalized to E.164 `+2519…` (`src/lib/phone.ts`).
- No hard deletes (soft `active` flags), no clinical data in this slice.
- Appointments store UTC instants and are bucketed/displayed in the clinic
  timezone; follow-up due dates are clinic-local calendar days. Website
  requests store the preferred local day plus a free-text time window.
- Sales: server recomputes every line/total from DB prices (client price is
  never trusted); each `SaleLine` snapshots the name + price so later service
  price changes never touch history; confirmed sales are immutable (void only,
  with a reason and audit); receipt numbers are gap-free per year (atomic
  counter). RECEPTION only sees their own sales for the current clinic day and
  never revenue; revenue/void-approval/direct-void are OWNER-only.

## Manual test checklist

Automated: with the production server running, `bash scripts/smoke.sh` covers
items 1–5, 10, 13, parts of 15–24, the HR role/model checks, the
appointments/requests/follow-ups + public-intake checks and the messaging
page/role/API checks (173 checks, exits non-zero on failure); `npm test` adds
335 unit tests (41 files: phone normalization, duplicate detection, CSV import,
consent/audit helpers, role gates, money rounding, sale line snapshots, receipt
numbers + timezone boundary, revenue aggregation + period presets, shift
template generation + overlap + week grids, leave balances/overlap/duty
suppression, public doctor status projection, appointment status transitions +
double-booking/working-minutes, public request validation, message template
rendering, GSM-7/Unicode segment counting, SMS link building, task timing
(birthday/same-day/thank-you in Addis time), eligibility blocking, campaign
audience matching + exclusion counts, 6B recipient filters + group-safe rules,
group batch planning (40 → 15/15/10), library duplicate hashing, message-body
validation, messaging role rules, the XLSX writer +
export row/naming rules, and the public-site
privacy projection + booking schema + SEO/hreflang + announcement window). The
browser-level items below still need a human. The Playwright specs under `e2e/`
(`npm run test:e2e`) automate the authentication, role-access, patient→sale,
request→appointment, leave and messages flows against a seeded database; the
group-chat batch flow runs on the `mobile` project (`e2e/group-batch.spec.ts`).

1. `/login/wrong` → 404; `/login` → 404; correct secret → login page.
2. Wrong password ×5 → account locked (15 min), `LOGIN_FAILED` rows with IP.
3. Rate limit: >30 attempts / 5 min from one IP → rejected.
4. Login as RECEPTION → forced to `/change-password`; other pages bounce back
   until the password changes; new password must pass strength rules.
5. After change: lands on dashboard, session still valid, `PASSWORD_CHANGED` audited.
6. OWNER: create a user (weak password rejected, duplicate email rejected),
   edit, deactivate (cannot deactivate yourself), reset → one-time temp password.
7. OWNER: staff CRUD + photo upload (reject >2 MB / wrong type), deactivate.
8. OWNER: settings — bad time (`25:00`), bad timezone, close ≤ open all rejected;
   valid save audits `SETTING_UPDATED` with changed fields only.
9. OWNER: audit viewer — filter by user/entity/action/date, expand JSON, CSV export.
10. RECEPTION hitting `/users` directly → `/403`; menu hides OWNER-only items.
11. Locale switch EN ↔ AM (cookie) — all screens translate, including errors.
12. Mobile 360px: drawer nav, tables scroll horizontally, forms usable.
13. Headers present (`curl -I`): CSP, X-Frame-Options: DENY, nosniff.
14. `npm run lint && npm run typecheck && npm run messages && npm test` all green.
15. Patients list: search by name/phone fragment (partial `0911` finds
    `+251911…`), tag / consent / stop-messages / active filters, sortable
    columns, pagination, "no results" state, filtered CSV export.
16. New patient: `091122`, `8112233445`, `abc` rejected with translated
    message; duplicate phone → "already exists" message with a link to the
    existing record; same name (similar spelling) + same birth date →
    non-blocking warning; consent checkbox stores date + user and audits both
    ticking and un-ticking.
17. Patient detail: tap-to-call link uses stored E.164, tags editor adds and
    removes tags on the fly (audited), "Patient asked to stop messages" with
    reason (audited), deactivate/reactivate (records are never deleted), the
    Sales tab lists this patient's sales with a confirmed total, and every open
    writes a `VIEW` audit row.
18. CSV import: template download has the right columns; missing file / wrong
    columns / >1000 rows are rejected with translated messages; preview shows
    per-row errors and never partially imports; confirming creates patients
    with consent **false** unless the file explicitly says `yes`; duplicates
    inside the file or already in the DB are reported, never silently
    overwritten.
19. STAFF (`doctor@visioneye.et`): `/patients` → `/403`, direct
    `/api/patients/export` → 403.
20. Services (OWNER): create/edit with bad category or non-numeric price
    rejected; changing a price writes a price-history row and leaves existing
    sale lines untouched; deactivated services disappear from the new-sale
    picker but stay in history.
21. New sale (OWNER/RECEPTION): patient type-ahead by name/phone (or Walk-in),
    optional doctor, add services with qty (duplicates merge), live total is
    recomputed server-side; confirming assigns the next receipt number and
    prints A5 or 80mm thermal.
22. Sales list: filter by date range/patient/service/doctor/status/payment,
    paginate, export CSV; RECEPTION sees only their own current-day sales and
    no cross-day totals.
23. Sale detail: confirmed sales show the receipt and audit trail; OWNER can
    void directly, RECEPTION can request a void; the OWNER approves or rejects
    from the void-requests inbox. Confirmed sales cannot be edited.
24. Revenue dashboard (OWNER): today/week/month/last-month/custom presets,
    total/sales-count/average cards with previous-period %, breakdowns by
    service/category/doctor/payment method, daily trend; VOIDED shown
    separately and excluded from revenue.
25. Schedule (OWNER): add/edit/delete a shift — overlapping same-person shifts
    rejected; `end < start` shifts render overnight (next day); the weekly
    template panel previews the generated week (respects already-booked days),
    applying + "copy last week" create shifts with audit rows.
26. Schedule scoping: RECEPTION sees the full team grid but no add/edit/delete/
    template controls; STAFF sees only their own week.
27. Leave (STAFF): request annual/sick leave, whole or half day; start/end
    validation, range-too-long and overlap-with-approved rejected with
    translated messages; balance card reflects annual days minus approved;
    request and cancel are audited (`LEAVE_REQUESTED`, `LEAVE_CANCELLED`).
28. Leave approvals (OWNER): the inbox lists requested leave with an
    appointment-collision hint (real appointments overlapping the leave range);
    approve/reject records a note; approving more days than the balance is
    blocked; **the OWNER cannot decide their own leave** (decided by a peer /
    rejected).
29. Leave calendar (OWNER + RECEPTION): 30-day team table shows approved leave
    per day and the SMART template days; forbidden for STAFF.
30. Public feed: `curl /api/public/doctors` with no session returns only
    published doctors' `id/name/specialty/photoUrl/status/todayShifts/
nextShiftStartsAt` + `generatedAt`, has `Cache-Control: public, max-age=60`,
    never shows phone/email/internal/leave-reason fields, shows today's shifts
    and "On leave" when approved leave covers today; dashboard on-duty widget
    matches for logged-in staff.
31. Appointments (OWNER/RECEPTION): list/day/week views, doctor filter, create
    with patient type-ahead + doctor/service/duration; a clashing slot warns
    (double-booked / outside shift / on leave) and can be saved with an
    override; transitions follow the allowed graph (Confirm → Complete /
    No-show / Cancel), terminal states are locked for non-OWNERs, and OWNER
    overrides are audited.
32. Website requests (OWNER/RECEPTION): status tabs (New/Contacted/Converted/
    Declined/Spam) plus a New count; opening the convert dialog picks a doctor
    and books the appointment — a matching phone reuses the patient, otherwise a
    patient is created, and the request becomes CONVERTED.
33. Follow-ups (OWNER/RECEPTION): tabs for open/overdue/done/cancelled with a
    patient filter; overdue rows highlighted; mark-done and cancel are audited.
    STAFF is denied all three pages (`/403`).
34. Public intake: `curl -X POST /api/public/requests` with no session — invalid
    JSON → 400, bad fields/phone → 422 with field errors, a valid payload → 200
    `{ok:true,id}` and stores only `ipHash`; repeating fast hits the per-IP
    rate limit (429).
35. Messages outbox (OWNER/RECEPTION): seeded reminders/recalls/birthdays appear
    under Overdue / Due now / Later today; the sidebar badge and browser-tab
    count (`/api/messages/count`) match; a BLOCKED row shows the reason
    (no consent / opted out / invalid phone / inactive / source cancelled).
36. Send flow: "Send" opens the phone's Messages app pre-filled. **Test on a
    real Android device** (`sms:+251…?body=…`) **and a real iPhone**
    (`sms:+251…&body=…`) — the message and number must be correct and nothing
    is sent until the user taps send in the phone app; returning to the tab
    opens the "Did you send…?" sheet; Yes → SENT, Not yet → back to pending,
    Skip requires a reason. "Copy message" / "Copy number" work as fallbacks.
37. Templates (OWNER): edit en/am with live preview; unknown `{vars}` are
    flagged; the character/part counter switches GSM-7 (160/153) ↔ Unicode
    (Amharic, 70/67); the "no diagnoses/treatments/specialties/prices" safety
    note is always shown; saving audits and the am drafts are flagged
    "needs native review".
38. Campaigns (OWNER; RECEPTION only when `reception_can_send_campaigns`):
    build an audience by tags/language/last-visit/upcoming/category/birthday,
    preview matched vs excluded counts (noConsent/doNotMessage/invalidPhone/
    inactive); creating makes one task per eligible patient (never a group
    message); the progress bar updates as messages are sent; "Copy all numbers"
    warns about group chats and requires the acknowledgement; the campaign cap
    from Settings is enforced.
39. Opt-out: "stop messages" on a patient cancels pending tasks and sets
    `doNotMessage`; re-enabling requires OWNER and both are audited; the send
    flow then refuses that patient.
40. Jobs: `POST /api/jobs/messages` without a bearer returns 503 when
    `CRON_SECRET` is unset (401 with a wrong secret once set); the OWNER
    "Run message jobs now" button reports a created/expired summary and is
    safe to run twice (idempotent).
41. Public website opens with no session: `/`, `/about`, `/services`, `/doctors`,
    `/book`, `/contact`, `/faq`, `/privacy` all render; any other path still
    bounces to the secret login and `/login/wrong` is a real 404.
42. Bilingual: switch EN ↔ AM from the header (cookie, no URL prefix) — hero,
    services, doctors, form labels, FAQ, hours and footer all translate; a
    `?lang=am` URL (e.g. from an hreflang tag) also renders Amharic and persists
    the cookie. Amharic copy is draft and marked for native review.
43. Home: hero, 6 featured services, "doctors on duty" strip (live status badges
    match `/api/public/doctors`, placeholder initials when no photo), why-us
    cards, testimonials (marked placeholder) and the CTA/location block.
44. Services: grouped by category, **no price is ever shown**; an empty catalogue
    shows the "being updated" message; each card links to booking.
45. Doctors: only published, active doctors appear; badges show Available now /
    On leave / Next available <time>; today's shift times are clinic-local.
46. Booking form: required name/phone/date/consent; bad phone or date shows a
    translated inline error; honeypot stays hidden; a valid submit shows
    "We received your request…" and creates an unhandled request (status NEW, no
    patient); 6 rapid submits from one IP → rate-limited message (429).
47. Contact: address, tap-to-call phone, WhatsApp/Telegram buttons (hidden when
    unset) and opening hours from Settings; the map is a link only (no tracking
    iframe). FAQ accordion keyboard-operable with `<details>`.
48. SEO/a11y: each page has a unique title + description + `<link rel="canonical">`
    and en/am `hreflang`; `/sitemap.xml` and `/robots.txt` respond; the default OG
    image renders; JSON-LD `MedicalClinic` is in the HTML; skip-link works; the
    page is usable by keyboard and Lighthouse (mobile) is ≥ 90 for
    performance/accessibility/SEO/best-practices.
49. CMS-lite (OWNER): `/dashboard/content` edits every text field (hero, about,
    hours, contact, announcement, FAQ, testimonials, privacy, footer); saving
    audits `SITE_CONTENT_UPDATED`; the announcement shows on the public site only
    while active and inside its date window.
50. Services admin (OWNER): mark a service "Show on the website" and add public
    descriptions; it appears on `/services` and `/book`; the price stays private;
    a doctor's photo only loads through `/api/public/doctors/<id>/photo` when the
    doctor is published.

## Public website — clinic-provided content

The site ships with clearly-marked **placeholder** copy (see `DEFAULT_CONTENT` in
`src/modules/public-site/content.ts`). Before launch, the clinic must supply:

- Final wording for hero, home intro, about story, opening hours, footer note and
  the **privacy policy** (the current text is an explicit placeholder and must be
  reviewed — it is not legal advice).
- Real contact details: address, phone, WhatsApp number, Telegram handle, map URL.
- Reviewed Amharic translations for every field (the seeded Amharic is a draft).
- Consent-approved patient testimonials (or leave the section empty).
- Doctor profiles: which staff are doctors, `showOnWebsite`, specialty and a
  photo (PNG/JPEG/WebP ≤ 2 MB) for each.
- The service catalogue: which services are public + their public descriptions
  (prices are never published).
- A decision on an analytics provider (none is loaded today) before any tracking
  is added.
- Production `PUBLIC_BASE_URL` (canonical origin) and, if wanted, Cloudflare
  Turnstile keys (disabled by default).

## Known gaps / next slices

The full, current list lives in [`docs/known-gaps.md`](docs/known-gaps.md).
Highlights:

- Public website is built (pages, booking, CMS-lite, SEO); it still needs the
  clinic's final copy/translations/photos (see the section above) and a human
  pass of the browser checklist.
- The public site's analytics hook (`Analytics`) is intentionally a no-op until a
  provider is chosen; enabling it also requires widening the CSP.
- Messages run in **manual-link** mode: the app prepares `sms:` links and the
  receptionist sends from the clinic phone. `GatewayProvider` is the seam for a
  real SMS API later; the optional daily email digest uses `EmailProvider`
  (console by default).
- Sales revenue comparison is preset-based only (no arbitrary grouped reports).
- Audit viewer JSON is pretty-printed, not diff-highlighted.
- In-memory rate limiter (per process) — swap for Redis if multi-instance.
- Uploads on local disk — `StorageProvider` is the seam for S3 later.
- Operational boards are capped (appointments 500, requests 200, messages 500,
  leave 100 rows) — see `docs/performance.md`.
- Playwright E2E specs exist but are not wired into CI; backup/restore needs
  PostgreSQL client tools (not in the embedded dev DB).
