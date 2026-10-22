# App shell, auth, users/staff/settings/audit/dashboard, exports — Laravel rebuild spec

Legacy source: Next.js 15/16 app router + Prisma + zod, `src/*` (read-only extraction).
Scope: app shell/layout/nav, credentials auth + forced password change, users, staff,
settings, audit, dashboard (widgets + daily summary + feedback page), global search,
uploads, CSV/XLSX export machinery, security headers. Later slices (patients, sales,
appointments, HR, messages, public site) are covered by sibling specs — they are only
referenced here where the shell exposes them (nav entries, role gates, export routes).

Conventions carried over (README "Money / time / phone"): money = `Decimal NUMERIC(12,2)`
never floats; instants stored UTC, displayed in the clinic timezone from Settings
(default `Africa/Addis_Ababa`); phones stored E.164 `+2519…`/`+2517…`; **no hard deletes**
(soft `active` flags). Error/audit strings are i18n **keys**, never UI text.

---

## 1. Routes & pages (legacy URL → purpose + role)

### Entry / public

| Legacy URL | Purpose | Gate |
| --- | --- | --- |
| `/`, `/about`, `/services`, `/doctors`, `/book`, `/contact`, `/faq`, `/privacy` | Public marketing site (the app root `/` is NOT the ERP). `?lang=en\|am` → `x-locale` header + `locale` cookie. | none |
| `/login/<LOGIN_SECRET_PATH>` | The only login page (client `LoginForm`). | exact secret else 404 |
| `/login`, any other `/login/*` | Empty **404** from proxy (scanners learn nothing). | 404 |

### ERP pages (route group `(app)` → layout enforces `auth()`)

| Legacy URL | Purpose | Gate (`requirePageRole` unless noted) |
| --- | --- | --- |
| `/dashboard` | Dashboard: role-specific stat cards, on-duty widget, quick links | OWNER, RECEPTION, STAFF |
| `/dashboard/daily?day=YYYY-MM-DD` | Daily sales summary, printable (`#print-daily`, `.no-print` controls), prev/next day, "email me this" button | OWNER (`SALES_OWNER_ROLES`) |
| `/dashboard/feedback?q=&website=` | Testimonials: filter by text + shown-on-website, star rating, website toggle, delete | OWNER (`FEEDBACK_ROLES`) |
| `/dashboard/content` | Website content editor (`SiteContentForm`) + "view site" link | OWNER (`CONTENT_ROLES`) |
| `/dashboard/services`, `/services/new`, `/services/[id]/edit` | Service catalog CRUD | OWNER (`SERVICES_ROLES`) |
| `/users`, `/users/new`, `/users/[id]` | User list (filters q/role/status, pagination 20), create form, edit form | OWNER |
| `/staff`, `/staff/new`, `/staff/[id]` | Staff list (filters q/status, pagination 20), create, edit | OWNER |
| `/settings` | Single settings form (all keys, one submit) | OWNER |
| `/audit` | Audit viewer: filters user/entity/action/from/to, expandable JSON diff, pagination 25, CSV/XLSX links | OWNER |
| `/change-password` | Self-service password change; reachable while forced | `requireAuth()` (any session, incl. `mustChangePassword`) |
| `/403` | Friendly forbidden page (403 + title/message + "back" link to `/`) | none (reached by redirect) |
| `/patients…`, `/sales…`, `/appointments`, `/follow-ups`, `/revenue`, `/schedule`, `/leave…`, `/messages…` | Other slices — see sibling specs | their own role constants |

Wrong role → `redirect('/403')`; pending password change → `redirect('/change-password')`;
no session → `redirect('/login/<LOGIN_SECRET_PATH>')` (query string cleared).

### API routes

| Legacy URL | Purpose | Gate |
| --- | --- | --- |
| `GET /api/search?q=` | Global search (patients/sales/appointments), `Cache-Control: no-store` | OWNER, RECEPTION; forced-pw → 302 `/change-password`; else **403** |
| `GET /api/uploads/[...key]` | Serve stored image bytes | signed-in session only, else **404** |
| `GET /api/audit/export?format=&userId=&entity=&action=&from=&to=` | Audit CSV/XLSX | OWNER (403 / 302 as above) |
| `GET /api/patients/export`, `/api/sales/export`, `/api/appointments/export`, `/api/leave/export`, `/api/messages/export` | Entity exports (§7) | per-entity roles |
| `GET /api/patients/template` | CSV import template, `filename="patients-template.csv"` | OWNER, RECEPTION |
| `GET /api/messages/count` | `{ok:true,count}` badge poll, no-store | session else 401 |
| `GET/POST /api/auth/[...nextauth]` | Auth.js handlers (Laravel: login/logout/CSRF endpoints) | — |

Proxy (`src/proxy.ts`, Next 16 middleware) matcher excludes `_next`, `api`, and any path
containing a dot → **every `/api/*` route must enforce its own session check**.

---

## 2. Auth flow

### Secret path
- Env var `LOGIN_SECRET_PATH` (name only): min 8 chars, `/^[A-Za-z0-9_-]+$/` (validated at boot by `src/env.ts`; `AUTH_SECRET` ≥32 chars, seed passwords ≥6, `DATABASE_URL` must be `postgresql://`).
- Login URL = `/login/${LOGIN_SECRET_PATH}`; also the Auth.js `pages.signIn` and every redirect target (`loginPath`).
- **Two-layer 404**: (1) proxy: `pathname === loginPath || pathname === loginPath + '/'` passes; `pathname === '/login' || pathname.startsWith('/login/')` → `new Response(null, {status:404})`; (2) page: `if (secret !== process.env.LOGIN_SECRET_PATH) notFound()`.
- Public paths skip the session sniff entirely; everything else requires a cookie named `authjs.session-token` or `__Secure-authjs.session-token` (presence only — signature verified later by `auth()` in the `(app)` layout / `requireRole`). Missing cookie → redirect to login path, `url.search = ''`.

### Session & cookie
- Strategy `jwt`, `maxAge = 12 * 60 * 60` (**12 hours**), `trustHost: true`.
- Session cookie options: `httpOnly: true`, `sameSite: 'lax'`, `secure: process.env.NODE_ENV === 'production'`, `path: '/'`, `maxAge: 43200`.
- JWT claims: `role`, `mustChangePassword` set on sign-in; session exposes `user.id = token.sub`, `user.role`, `user.mustChangePassword`.
- Locale cookie `locale`: `path=/`, `maxAge=31536000`, `samesite=lax` (set by `LocaleSwitcher` and by proxy for `?lang=`).

### Credential verification (`verifyCredentials`) — exact order
1. `email = trim(lowercase(raw))`; `password = raw`. Empty either → `null` (no DB, no audit).
2. **Rate limit** before any DB work: key `login:${ip ?? 'unknown'}`, `checkRateLimit` default **max 30 per 5 min (300 000 ms)** sliding window, process-local; blocked → return `null` (no audit row, no counter change). IP = first `x-forwarded-for` value else `x-real-ip` else null.
3. Lookup user by email; verify against `user.passwordHash ?? DUMMY_HASH` (constant valid argon2id hash ⇒ constant time when no user matches).
4. **Failure** (`!user || !passwordOk`): if user exists and not already locked → `failedLoginCount += 1`; when count ≥ **5** set `lockedUntil = now + 15 min` (5th failure locks; earlier failures leave `lockedUntil = null`). Audit `LOGIN_FAILED`, entity `'User'`, `entityId = user.id ?? \`email:${email}\``, `new = { reason: user ? 'invalidPassword' : 'unknownEmail' }`, ip. Return `null`.
5. **Locked** (correct password but `lockedUntil > now`): audit `LOGIN_FAILED` `new={reason:'locked'}`; no counter change; `null`.
6. **Inactive** (`active === false`, correct password): audit `LOGIN_FAILED` `new={reason:'inactive'}`; `null`.
7. **Success**: `failedLoginCount=0`, `lockedUntil=null`, `lastLoginAt=now`; audit `LOGIN_SUCCESS` (entity `'User'`, entityId = id, no diff, ip); return `{id,email,name,role,mustChangePassword}`.
- Login UI shows only `auth.invalidCredentials` for any failure (never which check failed).
- After sign-in: `mustChangePassword` → push `/change-password`, else `/dashboard` (never `/`).

### Forced password change + auto re-login
- New/edited/reset users get `mustChangePassword: true` (Prisma default is also `true`; seed users are created with `false`).
- Server side: `requireRole()` throws `PASSWORD_CHANGE_REQUIRED` **before** the role check; `requirePageRole()` → `redirect('/change-password')`; `withAuth()` → `{ok:false,error:'passwordChangeRequired'}`; API routes → `302 /change-password` (or 403 for role failures).
- Client side: `AppShell` computes `forced = user.mustChangePassword && pathname !== '/change-password'` → `router.replace('/change-password')` and renders `null` instead of children (nothing else is visible while forced).
- After a successful change, `ChangePasswordForm` immediately calls `signIn('credentials', {email, password: newPassword, redirect:false})` so the JWT claim flips to `false`, then `router.push('/dashboard'); router.refresh()` — without the re-login the stale claim would bounce back.

### Change password rules
- Action requires a session (`requireAuth`), schema `changePasswordSchema`, then `changeUserPassword`:
  - `currentPassword` wrong → field `currentPassword` = `currentPasswordWrong`.
  - strength via `passwordError(new, {email, name})` → field `newPassword` = `passwordTooShort | passwordCommon | passwordPersonal`.
  - schema extras: `new === current` → `newPassword` = `passwordSame`; `new !== confirm` → `confirmPassword` = `passwordMismatch`; all three min 1 → `required`.
- On success: argon2id hash, `mustChangePassword = false`, audit `PASSWORD_CHANGED` (entity `User`, old `{passwordHash}`, new `{passwordHash}` → both stored as `"[REDACTED]"`).
- Password policy: `MIN_PASSWORD_LENGTH = 10`; reject ~110-entry common list (lowercase set incl. `password`, `password123`, `123456`, `qwertyuiop`, `admin123`, `letmein`, `changeme`, `temp123`, …); reject case-insensitive containment of the email local part (if ≥4 chars) or the trimmed name (if ≥4 chars) → `passwordPersonal`. Temp password: **14 chars** from `abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789` via `crypto.getRandomValues`.

### Logout
- User menu / mobile drawer button → `signOut({ redirectTo: loginUrl })` (session cookie cleared, land on the secret login URL). Menu also has a `change-password` link and shows the email + role label.

---

## 3. Validation schemas (zod → Laravel validator)

Zod messages are i18n **keys**; client renders `t('validation.' + key)` per field, keyed by
field path (`issue.path.join('.')`, `'_'` when unkeyed). Global errors go through
`ActionError` (allow-list under `common.*`): `forbidden, unknownError, validationError,
passwordChangeRequired, cannotDeactivateSelf, saved, noFile, fileTooLarge, emptyFile,
confirmationRequired, missingColumns, tooManyRows` — unknown keys render nothing.

**Envelope** (`src/lib/action.ts`): `{ok:true,data?}` | `{ok:false,error?,fields?,existing?}`;
`withAuth(roles, handler)` maps `ValidationError` → `error:'validationError'` + `fields`,
`PASSWORD_CHANGE_REQUIRED` → `'passwordChangeRequired'`, `FORBIDDEN` → `'forbidden'`,
rethrows `redirect()` and unknown errors.

### Shared
- `emailField`: trim → lowercase, max 254 (`tooLong`), `/^[^@\s]+@[^@\s]+\.[^@\s]+$/` (`invalidEmail`).
- `phoneField` (staff): `''` → `null`; else `normalizePhone` must be non-null else `invalidPhone`; output E.164 (`09…`/`07…`/`9…`/`7…`/`+2519…` → `+2519…`; separators `space - . ( )` stripped).

### User form (`userCreateSchema` / `userUpdateSchema` / `userPasswordSetSchema`)
| Field | Rules | Error keys |
| --- | --- | --- |
| `name` | trim, 1–100 | `required`, `tooLong` |
| `email` | `emailField` | `invalidEmail`, `tooLong`; duplicate (P2002) → `emailTaken` |
| `role` | `OWNER\|RECEPTION\|STAFF` | `invalid` |
| `staffId` | trim, ≤64, optional (empty → `null`); must exist (P2003) | `unknown` on `staffId` |
| `password` | create only, min 1 → then `passwordError` in the action | `required`, `passwordTooShort`, `passwordCommon`, `passwordPersonal` |
| `password`+`confirmPassword` (set form) | min 1; must match | `required`, `passwordMismatch` (on `confirmPassword`), strength on `password` |

Update omits `password`. Missing target row → `{ok:false,error:'unknownError'}`.

### Staff form (`staffFormSchema`)
| Field | Rules | Error keys |
| --- | --- | --- |
| `fullName`, `jobTitle` | trim, 1–100 | `required`, `tooLong` |
| `isDoctor`, `showOnWebsite` | checkbox: `true`/`'true'` → true | — |
| `specialty` | trim ≤100, `''` → `null` | `tooLong` |
| `phone` | see `phoneField` | `invalidPhone` |
| `photo` | optional; `File` (`invalid`); mime ∈ {`image/png`,`image/jpeg`,`image/webp`} (`photoInvalidType`); size ≤ **1 048 576 B** (`photoTooLarge`) | `photoInvalidType`, `photoTooLarge` |

Upload key = `staff-<uuid>.<png|jpg|webp>`; stored value is `/api/uploads/<key>`.
`photoExtension()` maps mime → `png|jpg|webp` (`image/jpeg` → `jpg`).

### Settings form (`settingsFormSchema`) — see table in §3b
`working_hours.<day>.open|close` must match `/^([01]\d|2[0-3]):[0-5]\d$/` (`invalidTime`);
`close > open` in minutes unless `closed` (`endTimeAfterStart`). Coerced ints:
`z.coerce.number('invalid').int('invalid').min(1,'positive')` (+ `.max()` for caps).

### Change password — §2 (Change password rules).

### 3b. Settings keys table (all keys; seed defaults from `prisma/seed.ts`, fallbacks from `settingsFromMap`)

| Key | Default | Validation (error key) | Meaning |
| --- | --- | --- | --- |
| `clinic_name` | `Vision Eye Clinic` | trim, 1–100 (`required`,`tooLong`) | Display name in headers/emails |
| `currency` | `ETB` | trim, 1–10 (`required`,`tooLong`) | Currency for `formatMoney` |
| `timezone` | `Africa/Addis_Ababa` | ∈ `Intl.supportedValuesOf('timeZone')` (`invalidTimezone`) | Display/query timezone |
| `working_hours` | mon–fri `08:00–17:00` open, sat `08:00–12:00` open, sun `08:00–12:00` **closed** | per day: `HH:MM` (`invalidTime`), `close > open` unless closed (`endTimeAfterStart`) | Clinic hours (JSON object keyed `mon…sun`) |
| `reminder_send_time` | `14:00` | `HH:MM` (`invalidTime`) | SMS/reminder send clock time |
| `reminder_send_by_offset_minutes` | `60` | int ≥1 (`invalid`,`positive`) | Send reminders N minutes before |
| `followup_lead_days` | `3` | int ≥1 | Default follow-up lead time |
| `recall_months` | `12` | int ≥1 | Recall interval |
| `review_link` | `''` | ≤500 (`tooLong`), `''` or `/^https?:\/\/\S+$/` (`invalidUrl`) | Public review URL |
| `sms_mode` | `MANUAL_LINK` | `MANUAL_LINK\|GATEWAY` (`invalid`) | Manual link vs gateway sending |
| `reception_can_send_campaigns` | `false` | bool | Lets RECEPTION send campaigns |
| `campaign_max_recipients` | `300` | int 1–300 (`positive`,`tooHigh`) | Campaign recipient cap |
| `digest_enabled` | `false` | bool | Enables scheduled daily message-digest email (§9) |
| `digest_email` | `''` | ≤200 (`tooLong`), `''` or email (`invalidEmail`) | Digest recipient (falls back to OWNER email) |
| `default_language` | `en` | `en\|am` (`invalid`) | Public-site default language |
| `group_sms_allow_for_filtered` | `false` | bool | Allow filtered group SMS |
| `group_sms_max_recipients` | `15` | int 1–100 (`positive`,`tooHigh`) | Group SMS recipient cap |

- Exactly **17 form keys** in `SETTING_KEYS` (order above); `toSettingValues()` must emit exactly that key set (unit-tested).
- Non-form system key: `digest_last_sent` (`YYYY-MM-DD`, written by the job, never in the form).
- Read-side fallbacks: unknown `sms_mode` → `MANUAL_LINK`; non-`am` language → `en`; `campaign_max_recipients` clamped to [1,300]; `group_sms_max_recipients` clamped to [1,100]; missing day object → default hours; missing/blank string → fallback.
- Save: `withAuth(['OWNER'])`, validate, **upsert all 17 keys** (`value`, `updatedBy = actor.id`), then audit `SETTING_UPDATED` with the full old/new maps → diffing stores **changed fields only**.

---

## 4. Role matrix (page/action/API × OWNER/RECEPTION/STAFF)

| Surface | OWNER | RECEPTION | STAFF |
| --- | --- | --- | --- |
| `/dashboard` | ✔ | ✔ | ✔ |
| `/dashboard/daily`, daily print, daily email action | ✔ | ✖ (→/403) | ✖ |
| `/dashboard/feedback` (+ website toggle/delete actions) | ✔ | ✖ | ✖ |
| `/dashboard/content`, `/dashboard/services*` | ✔ | ✖ | ✖ |
| `/users*`, `/staff*`, `/settings`, `/audit`, `/api/audit/export` | ✔ | ✖ | ✖ |
| User actions (`createUserAction`, `updateUserAction`, `setUserActiveAction`, `resetUserPasswordAction`, `setUserPasswordAction`) | ✔ | ✖ (`forbidden`) | ✖ |
| Staff actions (`createStaffAction`, `updateStaffAction`, `setStaffActiveAction`) | ✔ | ✖ | ✖ |
| `saveSettingsAction` | ✔ | ✖ | ✖ |
| `changePasswordAction` / `/change-password` | ✔ | ✔ | ✔ (any session) |
| `/api/search`, `GlobalSearch` input in header | ✔ | ✔ | ✖ (hidden + 403) |
| `/api/uploads/*` | ✔ | ✔ | ✔ (any session; 404 otherwise) |
| `/api/patients/export` + template, `/api/appointments/export` | ✔ | ✔ | ✖ |
| `/api/sales/export` | ✔ | ✔ (scoped: own sales, current clinic day only) | ✖ |
| `/api/leave/export` | ✔ (team) | ✔ (team) | ✔ (own rows only) |
| `/api/messages/export`, `/api/messages/count`, messages badge, `MessagesTicker` | ✔ | ✔ | ✖ |
| `/patients*`, `/sales*`, `/follow-ups`, `/messages*` | ✔ | ✔ | ✖ |
| `/revenue`, `/sales/voids`, `/leave/inbox`, messages `templates`/`reports` | ✔ | ✖ | ✖ |
| `/schedule`, `/leave` | ✔ | ✔ | ✔ |
| `/leave/calendar` | ✔ | ✔ | ✖ |

OWNER-only surfaces (summary): users, staff, settings, audit, daily summary, website
content, services, feedback, revenue, voids, leave inbox, message templates/reports.
UI hiding is `<RoleGate>` / nav filtering only — **server enforcement is the boundary**
(`requireRole` for actions/pages, per-route checks in APIs).

---

## 5. Audit actions (exact strings)

`writeAudit({user, entity, entityId, action, old?, new?, ip?})`: diffs old/new (top-level
keys, deep compare), redacts secret keys (`password, passwordhash, password_hash,
newpassword, oldpassword, secret, token, authtoken, apisecret` → `"[REDACTED]"`), stores
**only changed fields**, stores `Prisma.DbNull`/SQL NULL when no diff, `userId NULL` for
anonymous events, ip = `x-forwarded-for` (first hop) else `x-real-ip` else NULL, and
**never throws** (logs `[audit] failed to write audit log:` instead).

| Action (exact) | entity | entityId | old/new payload |
| --- | --- | --- | --- |
| `USER_CREATED` | `User` | new id | new: `{name,email,role,staffId,active:true}` |
| `USER_UPDATED` | `User` | id | old/new: `{name,email,role,staffId}` |
| `USER_STATUS_CHANGED` | `User` | id | old/new: `{active}` |
| `USER_PASSWORD_RESET` | `User` | id | old/new: `{mustChangePassword}` (temp password itself never stored) |
| `USER_PASSWORD_SET` | `User` | id | old/new: `{passwordHash:'[REDACTED]', mustChangePassword}` |
| `PASSWORD_CHANGED` | `User` | id | old/new: `{passwordHash:'[REDACTED]'}` (actor = self) |
| `STAFF_CREATED` | `Staff` | new id | new: `{fullName,jobTitle,isDoctor,specialty,phone,showOnWebsite,photoUrl,active:true}` |
| `STAFF_UPDATED` | `Staff` | id | old/new: same 7 fields (`photoUrl` included) |
| `STAFF_STATUS_CHANGED` | `Staff` | id | old/new: `{active}` |
| `SETTING_UPDATED` | `Setting` | `'settings'` | old/new: settings maps → **changed keys only** |
| `LOGIN_SUCCESS` | `User` | user id | no diff, ip |
| `LOGIN_FAILED` | `User` | user id or `email:<email>` | new `{reason: invalidPassword \| unknownEmail \| locked \| inactive}`, ip |
| `DAILY_SUMMARY_EMAILED` | `sale` | `dayKey` (`YYYY-MM-DD`) | new `{to, mode:'CONSOLE'\|'SMTP'}` |
| `EXPORTED` | `<entity>` (e.g. `auditLog`, `patient`, `sale`, `appointment`, `leaveRequest`, `messageTask`) | `'export'` | new `{…filters, format:'csv'\|'xlsx'}` |
| `VIEW` | `Patient` | patient id | no diff, ip — **dedupe rule**: run in a transaction taking `pg_advisory_xact_lock(hashtext('view:<patientId>:<userId>'))`, skip insert when a row for same `entity/entityId/action/userId` exists with `createdAt >= now-60_000` (`VIEW_DEDUPE_MS`); failures logged, page still renders |
| `FEEDBACK_SUBMITTED`, `FEEDBACK_WEBSITE_CHANGED`, `FEEDBACK_DELETED` | `Feedback` | id | (feedback slice) |

---

## 6. Business rules & invariants (numbered, exact)

1. **No hard deletes** for users/staff: only `active` flips (`USER_STATUS_CHANGED` / `STAFF_STATUS_CHANGED`). Feedback rows are the only deletion in this scope (`FEEDBACK_DELETED`).
2. **Cannot deactivate yourself**: `setUserActiveAction` with `active=false` and `id === actor.id` → `{ok:false,error:'cannotDeactivateSelf'}`; the UI also disables the button (`disabled={activePending || (isSelf && active)}`, title = `common.cannotDeactivateSelf` = "You cannot deactivate your own account."). No-op toggles (target state == current) return `ok()` **without** an audit row.
3. **One-time temp password**: reset generates a 14-char random password, sets `mustChangePassword=true`, clears `failedLoginCount`/`lockedUntil`, returns `{tempPassword}` once in an amber panel with a Copy button + hint `users.tempPasswordHint` ("Shown once — give it to the user…"); it is never logged or audited. Client `window.confirm` on deactivate (`users.deactivateConfirm`) and reset (`users.resetConfirm`).
4. **Owner-chosen password** (`setUserPasswordAction`): same strength rules, clears lockout (`failedLoginCount=0`, `lockedUntil=null`), optional `mustChange=true` checkbox (`users.mustChangeLabel`).
5. **Photo upload**: PNG/JPEG/WebP only; server limit **1 MiB** (`MAX_PHOTO_BYTES = 1024*1024`) although the UI hint/README say "up to 2 MB" (see Ambiguities); key `staff-<uuid>.<ext>`; keys must match `/^[A-Za-z0-9][A-Za-z0-9._-]*$/` and contain no `..` (else `Unsafe storage key:`), local disk also resolves and requires the path to stay under `UPLOAD_DIR`; replacing a photo removes the old object; **served only to signed-in users** (no session → 404).
6. **Upload serving**: single-segment key only (`key.length !== 1` → 404), ext allow-list `png|jpg|jpeg|webp` else 404, `Content-Type` from ext, `Cache-Control: private, max-age=3600`, `X-Content-Type-Options: nosniff`. (Patient ID photos are additionally client-resized to ≤800 px JPEG q0.7 via `resizeImageFile`; staff photos are not.)
7. **Login lockout**: 5 consecutive failures → 15-minute lock; success resets counter+lock and stamps `lastLoginAt`; the counter only increments while unlocked.
8. **Login rate limit**: 30 attempts / 5 minutes per IP, in-memory sliding window (process-local; prune when >5 000 buckets). Blocked attempts are dropped before the DB and are **not** audited.
9. **Timing equalization**: always verify against `DUMMY_HASH` when the user is unknown; a generic `auth.invalidCredentials` message for every failure class.
10. **Exports**: `?format=csv|xlsx` (anything else → `csv`); filename `<entity>-<YYYY-MM-DD>.csv` (XLSX swaps the extension to `.xlsx`); every export writes an `EXPORTED` audit row first and **never blocks** on audit failure; row caps: patients 5 000 (`EXPORT_ROW_CAP`), sales 5 000 (`SALES_EXPORT_CAP`), audit 5 000, appointments/leave/messages uncapped within their filter scope (messages = today's outbox only).
11. **Filter hygiene**: list/export query params are truncated (`q` ≤100, ids ≤64, dates ≤10 + `/^\d{4}-\d{2}-\d{2}$/`, `status` ≤32); unknown enum values are dropped rather than erroring.
12. **Pagination**: users/staff page size **20** (`USERS_PAGE_SIZE`, `STAFF_PAGE_SIZE`), audit **25** (`AUDIT_PAGE_SIZE`); page is clamped into `[1, pageCount]`, `pageCount = max(1, ceil(total/size))`; UI shows `common.showing` = "Showing {from}–{to} of {total}" and disabled Previous/Next at the edges.
13. **Global search contract**: roles OWNER+RECEPTION; `q` trimmed, sliced to 100; `<2` chars → all three groups empty (200, `no-store`); patient group only queried when `q.length >= 3` (name contains-insensitive + phone variants), sales by receipt/patient/phone, appointments by patient name; **max 6 hits per group**, sorted by `title` (`localeCompare`), response `{patients:[],sales:[],appointments:[{id,title,subtitle,href}]}` with `href` `/patients/<id>`, `/sales/<id>`, `/appointments?view=day&day=<clinicDateKey>`; subtitles: phone; `receipt · total · <clinic-local time>`; `clinicDateKey · doctor · STATUS`.
14. **Badges**: `(app)` layout computes `messageCount = countDueTasks()` for OWNER/RECEPTION only (0 for STAFF, `catch → 0`); `AppShell` puts it as the `badge` on the `/messages` nav item; `MessagesTicker` polls `/api/messages/count` and prefixes the tab title. Nav modules with no visible items are removed entirely.
15. **Audit never breaks the business operation** (separate try/catch, `console.error` only) — same for the `VIEW` logger.
16. **Emails stored lowercase/trimmed**; unknown/duplicate email → `emailTaken` on the `email` field.
17. **Forced change is enforced in three layers** (client bounce, server action refusal, API 302/403) — see §2.
18. **Idempotence**: `setUserActiveAction`/`setStaffActiveAction` on an unchanged value → `ok()` with no write/audit; missing target row → `fail('unknownError')`.
19. **Secret login hygiene**: no `/login` index, no redirect from wrong secrets (real 404), login path never appears in nav; logout returns to the secret path.

---

## 7. Exports conventions (per entity)

Common pipeline: `requireRole` → parse/limit filters → fetch → `auditExport(actor, entity, filters, format)` (action `EXPORTED`, entityId `'export'`) → `exportResponse(filename, format, headers, rows)`.

- **CSV**: RFC-4180 quoting (`"` doubling, quoting when cell contains `"` `,` `\n` `\r`), `null/undefined` → empty, UTF-8 **BOM** prefix, `\r\n` row endings with a trailing `\r\n`. Headers: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="<name>.csv"`, `Cache-Control: no-store`.
- **XLSX**: dependency-free OPC/ZIP writer (STORE method), inline strings, one sheet named after the filename stem sanitized to 31 chars. `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment; filename="<name>.xlsx"`, `Cache-Control: no-store`. Row cell count must be ≤ header count (throws otherwise).
- **Filename pattern**: `<entity>-<YYYY-MM-DD>.csv` where the date is the **server UTC date** (`new Date().toISOString().slice(0,10)`), extension swapped for `.xlsx` when `format=xlsx`.
- **Auth**: identical in every route — no session → `403`; `mustChangePassword` → `302` to `/change-password`; wrong role → `403`.
- **UI**: `ExportLinks({base, query})` renders two `<a download>` links, `href = base + query + (&|?) + format=csv|xlsx`, labels `export.formatCsv` / `export.formatXlsx`; `query` includes its leading `?` so the separator is `&` when a query is present.

| Route | Roles | Filename stem | Columns (exact order) |
| --- | --- | --- | --- |
| `/api/patients/export` | OWNER, RECEPTION | `patients-<date>` | `id, idNo, fullName, phone, sex, birthDate, preferredLanguage, tags, doNotMessage, active, createdAt` (tags joined `"; "`; dates ISO `YYYY-MM-DD`; cap 5 000) |
| `/api/patients/template` | OWNER, RECEPTION | `patients-template.csv` (fixed) | `IMPORT_COLUMNS` + one example row `Example Patient, 0912345678, MALE, 1990-01-15, glasses, true, en` |
| `/api/sales/export` | OWNER, RECEPTION (RECEPTION auto-scoped: `createdBy=self`, `from=to=todayKey`) | `sales-<date>` | `receiptNumber, saleDate, patient, doctor, paymentMethod, status, lines, total, createdBy` (`saleDate` ISO; cap 5 000) |
| `/api/appointments/export` | OWNER, RECEPTION | `appointments-<date>` | `id, startsAt, endsAt, patient, patientPhone, doctor, service, status, createdFrom, notes` (times clinic-local) |
| `/api/leave/export` | all 3 (STAFF → own rows) | `leave-<date>` | `id, staff, type, startDate, endDate, halfDay, reason, status, decisionNote, createdAt` (dates `YYYY-MM-DD`, created clinic-local) |
| `/api/messages/export` | OWNER, RECEPTION | `messages-<date>` | `group, dueAt, handledAt, status, patient, patientPhone, template, sourceType, language, message` (today's outbox, buckets `dueNow, laterToday, overdue, doneToday, blocked`, sorted by `dueAt`) |
| `/api/audit/export` | OWNER | `audit-<date>` | `time, user, email, entity, entityId, action, ip, old, new` (time clinic-local; old/new JSON-stringified or empty; cap 5 000) |

---

## 8. Layout/nav

- **`(app)/layout`** (server): `auth()` → no user → `redirect(loginPath)`; computes `messageCount` (OWNER/RECEPTION only) and renders `<AppShell user loginUrl messageCount>` + `MessagesTicker` (title base `app.name`).
- **Header** (h-14, sticky): mobile ☰ toggle (`aria-label nav.menu`) → drawer; logo + `app.shortName` linking `/dashboard`; `GlobalSearch` (hidden for STAFF and on `<sm`); right side: `LocaleSwitcher`, user menu button (name + role label `common.roleOwner|roleReception|roleStaff` + initial avatar) → dropdown with email, `/change-password` (`common.changePassword`), sign-out (`common.signOut`).
- **Sidebar** (`md:flex`, w-56, sticky under header): collapsible module groups from `NAV_MODULES`, each item filtered by `roles`, modules with zero visible items dropped; active item styling; `NavModule` supports `expand`/`collapse` keys.

| Module key (labelKey) | Full name/acronym | Items (labelKey → href · roles) |
| --- | --- | --- |
| `dashboard` | — | `dashboard`→`/dashboard`·all; `dailySummary`→`/dashboard/daily`·OWNER |
| `pms` (PMS) | Patients Management System | `patients`→`/patients`·O,R; `sales`→`/sales`·O,R; `revenue`→`/revenue`·O; `appointments`·O,R; `followUps`→`/follow-ups`·O,R |
| `hrm` (HRM) | Human Resource Management System | `staff`·OWNER; `users`·OWNER; `leave`·all; `leaveInbox`→`/leave/inbox`·OWNER; `leaveCalendar`→`/leave/calendar`·O,R; `schedule`·all |
| `mms` (MMS) | Messages Management System | `messages`→`/messages`·O,R (+badge) |
| `cms` (CMS) | Clinic Management System | `services`→`/dashboard/services`·OWNER; `websiteContent`→`/dashboard/content`·OWNER; `feedback`→`/dashboard/feedback`·OWNER; `settings`→`/settings`·OWNER |
| `audit` | — | `audit`→`/audit`·OWNER |

- **Mobile drawer**: `fixed inset-0 z-40 md:hidden`, dim backdrop (`bg-slate-900/40`, `aria-label common.close`) + w-64 white panel with the same `NavModule` list and a red sign-out button; **Escape** closes drawer and menu; navigating closes both.
- **Locale switcher**: two buttons `EN` / `አማ`, sets `document.cookie = locale=<code>; path=/; max-age=31536000; samesite=lax`, then `router.refresh()`; `aria-pressed` marks the active one; locale resolution order: `x-locale` header (public `?lang=`) → `locale` cookie → `en`.
- **Print**: `PrintPageButton` (`common.print`, class `btn-secondary no-print`) calls `window.print()`; daily summary page uses `#print-daily` as the printable root and `.no-print` on controls; export links are also `.no-print`.
- **Forced change**: while `forced`, `<main>` renders `null` (children hidden) and the client redirects.

---

## 9. Dashboard widgets

`getDashboardData(user)` (clinic timezone from Settings; today = `clinicDateKey(now, tz)`;
week = Mon–Sun via `weekStartKey`):

| Widget / stat card | OWNER | RECEPTION | STAFF |
| --- | --- | --- | --- |
| `dashboard.title` + `dashboard.subtitle` ("Signed in as {name} ({role})") | ✔ | ✔ | ✔ |
| Revenue today → link `/dashboard/daily` (`statRevenueToday`, `formatMoney` with settings currency) | ✔ | — | — |
| Revenue week → `/revenue` (`statRevenueWeek`) | ✔ | — | — |
| Today's appointments → `/appointments?view=day&day=<todayKey>` (`statTodayAppointments`) | ✔ | ✔ | — (STAFF sees `statMyAppointmentsToday` → `/appointments`) |
| Pending voids → `/sales/voids` (`statPendingVoids`) | ✔ | — | — |
| Pending leave → `/leave/inbox` (`statPendingLeave`) | ✔ | — | — |
| Messages due (`dueToday + overdue`) → `/messages` (`statMessagesDue`; amber `risk` styling when >0) | ✔ | ✔ | — |
| Overdue follow-ups → `/follow-ups?status=overdue` (`statOverdueFollowUps`) | ✔ | ✔ | — |
| My shifts this week → `/schedule` (`statMyShiftsWeek`) | — | — | ✔ |
| Leave remaining → `/leave` (`statMyLeaveRemaining`, `—` when null) | — | — | ✔ |
| Messages banner (`messagesBanner` = "{due} message(s) due today, {overdue} overdue — open Messages") when due+overdue > 0 | ✔ | ✔ | hidden |
| **On-duty widget** (`hr.onDutyTitle` "Who is on duty now"): rows of doctor name, specialty, today's shifts as `start–end` joined `", "` (or `hr.noShiftsToday`), status pill `hr.onDutyNow` (emerald) / `hr.onLeave` (amber) / `hr.notOnDuty` (slate); hidden when no doctors | all roles | | |
| Quick links grid (`dashboard.quickLinks`) filtered by role — same href set as nav plus `newSale` (`/sales/new`), `leaveInbox`, `dailySummary`, `users`, `staff`, `settings`, `audit` | all roles | | |

**Daily summary page** (`/dashboard/daily?day=`): invalid/missing `day` → today. Cards:
total revenue, sales count, average sale, voided total (+count), receipt range
(`first – last` or `—`) + `dailyReceiptsCount`; three bucket tables (`byPayment`,
`byDoctor`, `byService`; columns `label/count/total`, empty → `dashboard.noSales`);
context card with `appointmentsToday`. Controls: previous/next day links, print button,
`DailyEmailButton`.

**Daily email (manual, OWNER)**: `sendDailySummaryEmailAction` → recipient =
`digest_email` (trimmed) else the actor's email; subject `<dayKey> — Daily sales summary`;
body from `buildDailySummaryText` (plain text lines: clinic name, `Date:`, `Generated:`
ISO, appointments, confirmed sales, total, average, voided, receipt range or `none`,
then "Revenue by service/doctor/payment method:" buckets or `none`); email provider mode
`CONSOLE` or `SMTP`; audit `DAILY_SUMMARY_EMAILED`. Button shows `emailSent`
("Email sent to {to} ({mode})") for 8 s, else `emailFailed`.

**Scheduled OWNER digest** (different feature, settings-driven): `runMessageJobs` sends,
once per clinic day when `digest_enabled === true` and `digest_email` non-empty and
clinic-local time ≥ **16:00**, subject `Vision Eye Clinic — <n> message(s) still unsent`,
body `<n> prepared message(s) are still waiting to be sent from the clinic phone.`, then
upserts `digest_last_sent = <dayKey>` so it never repeats that day.

---

## 10. i18n keys used (complete list)

Locales `en` (default) and `am`; files `messages/<locale>.json`; resolution
`x-locale` → `locale` cookie → `en`. Namespaces touched by this spec:

- **app**: `name, shortName, searchPlaceholder, searchEmpty, searchSearching, searchCount, searchGroupPatients, searchGroupSales, searchGroupAppointments`
- **nav**: `dashboard, users, staff, settings, audit, menu, patients, services, sales, revenue, newSale, schedule, leave, leaveInbox, leaveCalendar, appointments, followUps, messages, websiteContent, feedback, dailySummary, pms, hrm, mms, cms, expand, collapse`
- **common**: `save, saving, cancel, delete, edit, create, search, loading, empty, noResults, confirm, close, back, actions, status, all, active, inactive, page, showing, previous, next, yes, no, language, signOut, changePassword, required, optional, unknownError, forbidden, validationError, passwordChangeRequired, cannotDeactivateSelf, saved, deleted, copy, copied, name, email, role, roleOwner, roleReception, roleStaff, filter, clear, of, phoneTaken, print, noFile, idPhotoInvalidType, idPhotoTooLarge, patientRequired, fixFields`
- **auth**: `loginTitle, loginSubtitle, email, password, signIn, signingIn, invalidCredentials`
- **changePassword**: `title, subtitle, current, next, confirm, submit, success`
- **forbidden**: `title, message`
- **users**: `title, newUser, editUser, staffLink, noStaff, password, passwordHint, searchPlaceholder, roleFilter, statusFilter, create, update, lastLogin, created, updated, statusUpdated, deactivate, reactivate, deactivateConfirm, resetPassword, resetConfirm, tempPassword, tempPasswordHint, deactivated, reactivated, resetDone, setPassword, newPassword, confirmPassword, mustChangeLabel, setPasswordHint, passwordSet`
- **staff**: `title, newStaff, editStaff, fullName, jobTitle, isDoctor, specialty, phone, photo, photoHint, showOnWebsite, searchPlaceholder, create, update, created, updated, statusUpdated, deactivateConfirm, deactivate, reactivate`
- **settings**: `title, clinicName, currency, timezone, workingHours, open, close, closed, dayMon, dayTue, dayWed, dayThu, dayFri, daySat, daySun, reminderSendTime, reminderOffset, followupLeadDays, recallMonths, reviewLink, smsMode, smsManualLink, smsGateway, campaignMaxRecipients, digestEnabled, digestEmail, receptionCampaigns, defaultLanguage, groupSmsAllowFiltered, groupSmsMaxRecipients, groupSmsHint`
- **audit**: `title, user, entity, action, from, to, apply, export, time, ip, changes, noChanges, old, new, allUsers, allEntities, allActions`
- **dashboard**: `title, subtitle, comingSoon, quickLinks, statTodayAppointments, statOverdueFollowUps, messagesBanner, dailySummary, statRevenueToday, statRevenueWeek, statPendingVoids, statPendingLeave, statMessagesDue, statMyAppointmentsToday, statMyShiftsWeek, statMyLeaveRemaining, dailyTitle, dailySubtitle, generatedAt, previousDay, nextDay, dailyTotalRevenue, dailySalesCount, dailyAverageSale, dailyVoided, dailyReceiptRange, dailyReceiptsCount, byPayment, byDoctor, byService, dayContext, appointmentsToday, label, columnCount, columnColumnCount, columnTotal, noSales, sendDailyEmail, emailSending, emailSent, emailFailed` (note: the real key is `columnCount`, not `columnColumnCount`)
- **validation**: `required, invalid, tooShort, tooLong, invalidPhone, positive, tooHigh, minQuantity, invalidDate, invalidTime, endTimeAfterStart, invalidTimezone, invalidUrl, invalidEmail, emailTaken, passwordTooShort, passwordCommon, passwordPersonal, passwordSame, passwordMismatch, currentPasswordWrong, unknown, photoInvalidType, photoTooLarge, invalidSex, invalidLanguage, invalidConsent, tooMany, invalidCategory, invalidPrice, invalidQuantity, imageInvalidType, imageTooLarge, serviceInactive, serviceMissing, quantityTooHigh, noLines, tooManyLines, invalidPaymentMethod, invalidLeaveType, endBeforeStart, rangeTooLong, patientRequired, doctorRequired, invalidDuration, invalidStatus, invalidPreset, maxLength`
- **export**: `formatCsv, formatXlsx`
- **feedback** (dashboard feedback page): `title, intro, searchPlaceholder, websiteFilter, websitePublished, websiteHidden, rating, ratingValue, message, submitted, onWebsite, showOnWebsiteFor, deleteConfirm, empty`
- **hr** (only the on-duty widget): `onDutyTitle, noShiftsToday, onDutyNow, onLeave, notOnDuty`

Notable exact values: `common.cannotDeactivateSelf` = "You cannot deactivate your own
account."; `validation.currentPasswordWrong` = "Current password is incorrect";
`validation.photoTooLarge` = "Photo must be 2 MB or smaller";
`staff.photoHint` = "PNG, JPEG or WebP, up to 2 MB.";
`users.passwordHint` = "Minimum 10 characters. The user will be asked to change it at
first sign-in."; `users.tempPasswordHint` = "Shown once — give it to the user and ask them
to change it at sign-in."; `changePassword.success` = "Password changed.".
`npm run messages` verifies `en.json`/`am.json` key parity.

---

## 11. Test inventory (Vitest → PHPUnit)

### `src/modules/users/schemas.test.ts`
- `userPasswordSetSchema › accepts matching passwords` → `{password:'a-strong-passphrase', confirmPassword:'a-strong-passphrase'}` parses OK.
- `… › rejects a missing password` → `{password:'',confirmPassword:''}` fails, issue path[0] === `'password'`.
- `… › flags a confirmation mismatch on confirmPassword` → different confirm fails with message `'passwordMismatch'` on path `confirmPassword`.
- `userCreateSchema / userUpdateSchema › requires a password only on create` → create with `password:'x'` OK; create without password fails; update without password OK.
- `… › normalizes email to lowercase` → `'ABEBE@EXAMPLE.COM'` → `'abebe@example.com'`.
- `… › rejects an unknown role` → `role:'ADMIN'` fails.

### `src/modules/settings/settings-schema.test.ts`
- `accepts a valid payload and coerces numbers` → `60`, `3`, `12` as ints; `working_hours.sun.closed === true`.
- `rejects malformed times with the invalidTime key` → `reminder_send_time:'25:00'` → first issue `'invalidTime'`.
- `rejects an unknown timezone with invalidTimezone` → `'Mars/Olympus'` → `'invalidTimezone'`.
- `rejects a closing time at or before the opening time` → `09:00→09:00` and `17:00→08:00` → `'endTimeAfterStart'`.
- `still accepts a closed day with any valid times` → sun `09:00–09:30 closed:true` OK.
- `rejects zero and non-numeric integers with the right keys` → `'0'` → `'positive'`; `'abc'` → `'invalid'`.
- `rejects malformed review links and accepts empty or https` → `''` OK, `https://…` OK, `ftp://…` → `'invalidUrl'`.
- `rejects unknown sms_mode and default_language values` → `'SMOKE_SIGNAL'` / `'fr'` → `'invalid'`.
- `rejects an empty clinic name` → `'   '` → `'required'`.
- `toSettingValues › produces exactly the seeded storage keys with JSON-safe values` → sorted keys equal sorted `SETTING_KEYS`, `clinic_name`, numeric `followup_lead_days`, boolean `reception_can_send_campaigns`, `JSON.stringify` doesn't throw.

### `src/lib/login-policy.test.ts` (`NOW = 2026-10-08T10:00:00Z`)
- `locks the account on the 5th consecutive failure for 15 minutes` → after 5 calls count `5`, `lockedUntil === NOW + 900 000 ms`.
- `does not lock before the 5th failure` → attempts 1..4 keep `lockedUntil === null`.
- `isLocked is true only inside the lock window` → true at `lockedUntil`, false 1 ms later, false for `null`/`undefined`.
- `a successful login resets the counter and clears the lock` → `{failedLoginCount:0, lockedUntil:null, lastLoginAt:NOW}`.

### `src/lib/ratelimit.test.ts`
- `allows requests up to the default limit then blocks` → 30 × `true` then `false`; other key still `true`.
- `allows a custom limit/window` → `max:2` → true,true,false.
- `resets a bucket after its window expires` → blocked at 9 999 ms, allowed at 10 000 ms, blocked at 10 001 ms (window `10_000`).
- `prunes expired buckets when the map grows large` → 5 100 keys, expired key allowed again after pruning.
- `resetRateLimits clears all buckets` → key allowed again after reset.

### `src/lib/passwords.test.ts`
- `rejects passwords shorter than the minimum length` → 9-char and `''` → `'passwordTooShort'`.
- `rejects common passwords` → `password123`, `Password123`, `1234567890`, `qwertyuiop` → `'passwordCommon'`.
- `rejects passwords containing the personal email local part` → contains `abebekebede` → `'passwordPersonal'`; unrelated → `null`.
- `rejects passwords containing the user name` → contains `Mulugeta` → `'passwordPersonal'`.
- `accepts a strong unrelated password` → `R7#kQp2!vLmW`, `correct horse battery staple!!` → `null`.
- `generateTemporaryPassword › returns 14 chars from the unambiguous alphabet only` → 50 iterations, length 14, every char ∈ alphabet.
- `… › produces different values across calls` → two calls differ.

### `src/lib/audit-diff.test.ts`
- `redacts secret-looking fields anywhere in the value` → `passwordHash`, nested `token`, list item `password` all `'[REDACTED]'`, others kept.
- `stores only changed fields on each side` → changed `name`/`role` kept, unchanged `email` absent, `hasChanges` true.
- `reports no changes for identical objects` → `hasChanges` false (deep incl. arrays/objects).
- `redacts secrets inside the diff output` → both sides `'[REDACTED]'`, `active:false` preserved.
- `treats a missing field on one side as a change` → old `{staffId:'staff-1'}` vs `{}` → old keeps value, new `{}`.
- `compares Dates by value, not reference` → equal ISO dates → no changes.

### `src/lib/audit.test.ts` (mocks `prisma.auditLog.create` + `next/headers`)
- `audit clientIp › uses the first x-forwarded-for value` → `"1.2.3.4, 5.6.7.8"` → `'1.2.3.4'`.
- `… › falls back to x-real-ip`; `… › returns null when headers throw`.
- `writes a row when there is no old/new (plain event)` → `action:'VIEW'`, `oldJson === Prisma.DbNull`, `newJson === Prisma.DbNull`.
- `stores a redacted diff of old/new` → `{name:'Old', passwordHash:'[REDACTED]'}` vs new, `ip` preserved.
- `stores DbNull when there are no changes (old === new)`.
- `omits userId for anonymous events` → `userId === null`.
- `never throws when the underlying write fails` → resolves `undefined`.

### `src/lib/require-role.test.ts` (mocks `redirect` to throw `REDIRECT:<url>`, `loginPath='/login/secret-path'`)
- `requireRole`: anonymous → throws `REDIRECT:/login/secret-path`; matching role returns `{id,role,email}`; wrong role → `AuthorizationError` `code:'FORBIDDEN'`; `mustChangePassword:true` → `code:'PASSWORD_CHANGE_REQUIRED'`.
- `requireAuth`: reachable while forced (returns user with `mustChangePassword:true`); anonymous still redirects.
- `requirePageRole`: `FORBIDDEN` → `REDIRECT:/403`; pending change → `REDIRECT:/change-password`; success passes; redirect errors are never converted.
- `AuthorizationError carries the code` (`name === 'AuthorizationError'`).

### `src/lib/roles.test.ts`
- `lists exactly the three clinic roles` → `['OWNER','RECEPTION','STAFF']`.
- `isRole guards unknown values` → `'OWNER'` true, `'SUPERADMIN'`/`undefined` false.
- `hasRole is null-safe and only matches allowed roles` → membership checks incl. `null`/`undefined` false.

### `src/lib/xlsx.test.ts`
- `writes a valid PK zip with the required parts` → starts with `PK`; contains `[Content_Types].xml`, `_rels/.rels`, `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/worksheets/sheet1.xml`.
- `escapes XML and handles empty cells` → `&lt;evil &amp; &quot;quoted&quot;&gt;`, `<c/>` for null, `<c t="b"><v>1</v></c>` for true.
- `sanitizes invalid sheet names and caps their length` → `bad/name:with:…` → `bad name with many chars and mo` (31 chars).
- `throws on empty worksheets or oversize rows` → `toXlsx([])`, empty headers, row longer than headers all throw.

### Browser-level (`e2e/`, manual equivalents in README checklist 1–19)
- `smoke.spec.ts`: `owner signs in and lands on the dashboard`; `wrong password is rejected`; `unknown login path 404s`.
- `roles.spec.ts`: `owner sees the revenue link`; `reception has no revenue link and is redirected from /revenue`; `staff cannot open patient records`.
- `scripts/smoke.sh` (production server): items 1–5, 10, 13 + parts of 15–24 (173 checks).
- **No dedicated unit test** for `src/lib/csv.ts` (`toCsv`/`parseCsv`) — cover with round-trip PHPUnit tests (BOM, CRLF, quote doubling).

README checklist items relevant to this spec: 1 (`/login/wrong` + `/login` → 404), 2 (5 fails → 15-min lock, `LOGIN_FAILED` with IP), 3 (>30/5 min rejected), 4 (RECEPTION forced change, bounce, strength rules), 5 (after change lands on dashboard, session survives, `PASSWORD_CHANGED`), 6 (user CRUD, weak/duplicate rejected, no self-deactivate, one-time temp), 7 (staff CRUD + photo reject), 8 (settings rejects `25:00`/bad tz/`close ≤ open`, audit changed-fields-only), 9 (audit filters + JSON + CSV), 10 (`/users` → `/403`, menu hides), 11 (EN↔AM incl. errors), 12 (360 px drawer/scroll), 13 (`curl -I` headers), 19 (STAFF `/patients` → 403, export → 403).

---

## 12. Security headers + storage/upload behavior

### Headers (`next.config.ts headers()` on source `/:path*` — every path)
- `Content-Security-Policy` (join `'; '`), directives:
  - `default-src 'self'`
  - `script-src 'self' 'unsafe-inline'` (+ ` 'unsafe-eval'` **only when `NODE_ENV !== 'production'`**)
  - `style-src 'self' 'unsafe-inline'`
  - `img-src 'self' data: blob:`
  - `font-src 'self' data:`
  - `connect-src 'self'` (+ ` ws: wss:` **only in dev**)
  - `frame-src 'self' https://www.google.com` (the Google Maps embed)
  - `object-src 'none'`
  - `base-uri 'self'`
  - `form-action 'self'`
  - `frame-ancestors 'none'`
  - Prod example: `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data: connect-src 'self'; frame-src 'self' https://www.google.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Strict-Transport-Security: max-age=63072000; includeSubDomains` — **production only** (omitted in dev).
- Extra response headers outside `next.config`: uploads → `Cache-Control: private, max-age=3600` + `X-Content-Type-Options: nosniff`; search/count/messages-count → `Cache-Control: no-store`; all exports → `Cache-Control: no-store`.
- README check 13 expects `curl -I` to show CSP, `X-Frame-Options: DENY`, nosniff.

### Storage (`STORAGE_DRIVER` env: `db` default | `local`; `UPLOAD_DIR` default `storage/uploads`)
- Interface `StorageProvider`: `put(key, data) → key`, `get(key) → Buffer|null`, `remove(key)` (never errors on miss).
- **Key safety (both drivers)**: `/^[A-Za-z0-9][A-Za-z0-9._-]*$/` **and** no `..`, else throws `Unsafe storage key: <key>`. `LocalDiskStorage` additionally resolves the path and requires `full.startsWith(root + '/')` else throws `Path escapes storage root: <key>` (mkdir -p parent on write; `rm(..., {force:true})` on remove).
- **`DatabaseStorage` (default)**: rows in `StoredFile { key unique, mimeType, data }` — mime **detected from magic bytes** (PNG `89504e470d0a1a0a`, JPEG `ffd8ff`, WebP `RIFF….WEBP`, else `application/octet-stream`), persisted as Postgres BYTEA so uploads survive ephemeral disks; `remove` = `deleteMany({key})`.
- **`LocalDiskStorage`**: files under `resolve(cwd, UPLOAD_DIR)` — dev only (ephemeral on serverless).
- **Serving**: `GET /api/uploads/[...key]` (§6 rule 6) — session required, 1-segment key, ext allow-list, private cache.
- Env var names used by this layer (values never printed): `DATABASE_URL`, `AUTH_SECRET`, `LOGIN_SECRET_PATH`, `OWNER_EMAIL`/`OWNER_PASSWORD`, `RECEPTION_EMAIL`/`RECEPTION_PASSWORD`, `STAFF_EMAIL`/`STAFF_PASSWORD`, `UPLOAD_DIR`, `STORAGE_DRIVER`, `CRON_SECRET`, `PUBLIC_BASE_URL`, `TURNSTILE_SECRET_KEY`, `NEXT_PUBLIC_TURNSTILE_SITE_KEY`, `NODE_ENV`.

---

## Ambiguities resolved (for the Laravel build)

1. **Photo size limit**: code says `MAX_PHOTO_BYTES = 1024*1024` (1 MiB) while the UI hint, `validation.photoTooLarge` text and README say "2 MB". **Laravel enforces the legacy runtime behaviour: 1 MiB** (docs text unchanged); note in release docs that the UI text is inaccurate.
2. **`/` is the public marketing site**, never the ERP — post-login landing is `/dashboard`, and logout lands on the secret login URL (not `/`).
3. **Rate-limited login attempts are not audited** (the limiter returns before the DB); only verified-but-failed/successful attempts produce `LOGIN_FAILED`/`LOGIN_SUCCESS`.
4. **Counter increments vs lock**: while `lockedUntil > now` the failure counter is frozen; a *correct* password during the lock still audits `LOGIN_FAILED` with `reason:'locked'`.
5. **`SETTING_UPDATED` audits `entityId:'settings'`** (constant), and stores only keys that changed (`writeAudit` diff), even though all 17 keys are upserted.
6. **Two "digest" features**: manual end-of-day sales email (button on `/dashboard/daily`, action `DAILY_SUMMARY_EMAILED`) vs scheduled unsent-message digest (`digest_enabled` + `digest_email`, 16:00 clinic time, key `digest_last_sent`, subject "…message(s) still unsent"). Both must be rebuilt.
7. **Export dates** in filenames use the **UTC** day (`toISOString().slice(0,10)`), while row timestamps are rendered in the clinic timezone.
8. **`dashboard` i18n has `columnCount`/`columnTotal`/`label`** — a `columnColumnCount` key does not exist (typo guard noted in §10).
9. **`isRole`/`ALL_ROLES`** are the only role source of truth; role constants for other slices live in `src/modules/*/roles.ts` (`MESSAGE_ROLES`, `HR_VIEW_ROLES`, `APPT_ROLES`, `PATIENT_ROLES`, `SALES_ROLES`, `SALES_OWNER_ROLES`, `CONTENT_ROLES`, `SERVICES_ROLES`, `FEEDBACK_ROLES`, `FOLLOW_UP_ROLES`).
10. **`DAILY_SUMMARY_RECEIT_MAX = 5000` is exported but unused** — receipt range on the daily page is unbounded; do not invent a cap.
