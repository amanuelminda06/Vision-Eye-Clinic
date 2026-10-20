# Messaging app (actions/queries/UI) — Laravel rebuild spec

Scope: `src/modules/messaging/{provider,recipients,generate,campaign,actions,queries,roles,schemas,index}.ts`,
`components/**`, `src/app/(app)/messages/**`, `src/app/api/{messages/count,messages/export,jobs/messages}/route.ts`,
`docs/messaging-6b-manual.md`, `docs/roles.md`, `*.test.ts`. Pure logic (render/schedule/recipient-filter/
group-plan/templates/message-hash/eligibility) is specified in `docs/spec/messaging-core.md`; referenced here only
where the app half depends on it. Everything is **manual send**: the app never transmits SMS — it builds `sms:` links
and the operator confirms from the clinic phone.

Action envelope: every server action returns `ActionResult` = `{ok:true,data?}` | `{ok:false,error?,fields?,existing?}`
(`src/lib/action.ts`). `error` is an i18n key (rendered raw by toasts, `t()`-ed by the wizard). Standard errors from
`withAuth`: `'forbidden'` (wrong role), `'passwordChangeRequired'`, `'validationError'` (+ `fields` map of
`field → key`), default `'unknownError'`. Not-signed-in actions redirect (never converted).

## 1. Routes & pages (legacy URL → purpose)

| URL | File | Purpose | Guard |
|---|---|---|---|
| `/messages` | `messages/page.tsx` | Outbox board (today's queue) + link bar (New message, Export links, Library, Drafts, Templates, Campaigns; Reports link and `RunJobsButton` only if `user.role === 'OWNER'`) | `requirePageRole(MESSAGE_ROLES)` |
| `/messages/new` | `messages/new/page.tsx` | 3-step wizard (`presetDelivery="INDIVIDUAL"`, `createLabelKey="newMessage"`); query `?draft=<id>` / `?library=<id>` preloads step 2 | `MESSAGE_ROLES` |
| `/messages/templates` | `messages/templates/page.tsx` | Template editor (body/active per key+language) | `requirePageRole(MESSAGE_OWNER_ROLES)` = OWNER |
| `/messages/campaigns` | `messages/campaigns/page.tsx` | Campaign list + progress; "New campaign" link only if `canSendCampaigns(role, reception_can_send_campaigns)` | `MESSAGE_ROLES` |
| `/messages/campaigns/new` | `messages/campaigns/new/page.tsx` | Same wizard (`createLabelKey="createCampaign"`); if `!canSend` the wizard renders `t('campaignsForbidden')` instead of the form | `MESSAGE_ROLES` + runtime gate |
| `/messages/campaigns/[id]` | `messages/campaigns/[id]/page.tsx` | Campaign detail: progress bar, `CampaignQueue`, group batches when `deliveryMode==='GROUP'`; `notFound()` → 404; `canManage = canSendCampaigns(role, true)` | `MESSAGE_ROLES` |
| `/messages/drafts` | `messages/drafts/page.tsx` | Drafts board (`listDrafts(user.id)`) | `MESSAGE_ROLES` |
| `/messages/library` | `messages/library/page.tsx` | Library board (`listLibraryItems({includeArchived:false})`, `canManage = canManageLibrary(role)` = OWNER) | `MESSAGE_ROLES` |
| `/messages/reports` | `messages/reports/page.tsx` | Expired-not-sent report grouped by day | `requirePageRole(MESSAGE_OWNER_ROLES)` |
| `GET /api/messages/count` | `api/messages/count/route.ts` | Badge poll: `{ok:true,count}` (no role check — session only), `Cache-Control: no-store`, query errors → `count:0`; no session → **401** `{ok:false}` | session |
| `GET /api/messages/export` | `api/messages/export/route.ts` | CSV/XLSX export of today's outbox (see §7) | `requireRole(MESSAGE_ROLES)` → 403 / redirect `/change-password` |
| `POST /api/jobs/messages` | `api/jobs/messages/route.ts` | Cron entry → `runMessageJobs()` (see §6.26) | bearer `CRON_SECRET` |
| (layout) `src/app/(app)/layout.tsx` | — | Sidebar badge `messageCount = countDueTasks()` for OWNER/RECEPTION; mounts `MessagesTicker` for those roles | session |
| (patient tab) `patients/[id]/page.tsx` | `PatientMessagesList` | Read-only message history in the patient detail "Messages" tab (tab badge = `countPatientMessages`) | page's own guard |

`requirePageRole`: no session → redirect to secret login; `mustChangePassword` → redirect `/change-password`;
wrong role → redirect `/403` (§ docs/roles.md).

## 2. Server actions

All are `use server` actions with signature `(prev, formData) => Promise<ActionResult>`; all call `withAuth(roles, …)`.
Roles below = the `withAuth` list **before** extra gates. `validate(schema, …)` throws `ValidationError` →
`{error:'validationError', fields}`. No action emits optimistic-lock/version checks; none is transactionally idempotent
except where noted.

### 2.1 `openMessageTaskAction` → `ActionResult<OpenTaskResult>`
- Roles: `MESSAGE_ROLES`. Schema: `taskSendSchema` (`id`).
- Re-loads task; missing → `'notFound'`; status in `SENT|SKIPPED|CANCELLED` → `'taskAlreadyHandled'`.
- Re-checks live eligibility (`blockedReason`: `inactive → doNotMessage → invalidPhone → sourceCancelled` for
  `APPOINTMENT`/`FOLLOWUP` sources): if blocked → task set `status:'BLOCKED', blockedReason:reason`; else
  `status:'OPENED', blockedReason:null`.
- Returns `{status, blockedReason, renderedMessage, phoneE164}`. No audit.
- Idempotent for OPENED/BLOCKED (re-run recomputes and re-opens).

### 2.2 `confirmMessageSentAction` — "Yes, sent"
- Roles: `MESSAGE_ROLES`. Schema: `taskSendSchema`.
- Missing → `'notFound'`. Already `SENT` → `ok()` (no second audit). **Otherwise updates any status to `SENT`**
  (guard is only "not SENT"), sets `handledBy=actor.id, handledAt=now, blockedReason:null`.
- Audit: entity `MessageTask`, action `MESSAGE_SENT`. No `old/new`.

### 2.3 `postponeMessageTaskAction` — "Not yet"
- Roles: `MESSAGE_ROLES`. `taskSendSchema`. Missing → `'notFound'`; `SENT|CANCELLED` → `'taskAlreadyHandled'`.
- Sets `status:'PENDING'` only (a stored `blockedReason` is **not** cleared). No audit, idempotent.

### 2.4 `skipMessageTaskAction`
- Roles: `MESSAGE_ROLES`. `taskSkipSchema` (`id`, `reason` required ≤300).
- Missing → `'notFound'`; `SENT` → `'taskAlreadyHandled'` (note: `CANCELLED` **can** be skipped).
- Sets `status:'SKIPPED', skipReason, handledBy, handledAt`. Audit `MessageTask`/`MESSAGE_SKIPPED`, `new:{reason}`.

### 2.5 `editMessageTaskAction` — one-off body edit
- Roles: `MESSAGE_ROLES`. `taskEditSchema` (`id`, `message` required ≤1000).
- Missing → `'notFound'`; `SENT|CANCELLED` → `'taskAlreadyHandled'`.
- Sets `renderedMessage, editedBy, editedAt`. Audit `MessageTask`/`MESSAGE_EDITED` with
  `old:{message: previous}, new:{message}`. Returns `{message}`. Sensitive-word scan is **not** applied here.

### 2.6 `generateScheduleChangeAction`
- Roles: `MESSAGE_ROLES`. `appointmentId` (string, empty → `'notFound'`); `generateScheduleChangeTask` returns
  `'missing'` → `'notFound'`.
- Creates immediate `SCHEDULE_CHANGE` task with `sourceId = ${appointmentId}#sc#${Date.now()}` → **every click makes a
  new task** (unique tuple differs). Audit `Appointment`/`SCHEDULE_CHANGE_NOTICE_PREPARED`.

### 2.7 `runMessageJobsAction`
- Roles: `MESSAGE_OWNER_ROLES` (OWNER only). Ignores prev/formData. Runs `runMessageJobs(new Date())`, returns
  `JobSummary {expired, sameDay, birthdays, thankYous, followUps, recalls, digestSent}`. No audit, no guard against
  concurrent runs (safe by idempotent generation).

### 2.8 `saveMessageTemplateAction`
- Roles: `MESSAGE_OWNER_ROLES`. Schema `templateSaveSchema` (`id≤64, key, language, body 1..1000, active checkbox`).
- `validateTemplateBody(body)` fails → `fail('validationError', {body:'unknownVariable'})`.
- `id` must exist → else `'notFound'` (`key`/`language` are validated but the row is looked up by `id` only; they must
  equal the existing row's values as posted by the UI).
- Updates `body, active, updatedBy`. Audit `MessageTemplate`/`MESSAGE_TEMPLATE_UPDATED`, `old`/`new` = `{body,active}`.

### 2.9 `previewCampaignAction`
- Roles: `MESSAGE_ROLES`. Reads full recipient filter from form (`readRecipientFilter`: every field via `getAll` +
  comma-split, trim, dedupe, drop blanks).
- Returns `{eligible, matched, overCap, groupSafe, groupBlockReasons}`; group reasons are suppressed when
  `canOverrideGroupFilter(role)` (OWNER) **and** setting `group_sms_allow_for_filtered === true`. No audit.

### 2.10 `createCampaignAction` (also used by `/messages/new`)
- Roles: `MESSAGE_ROLES` **+ gate** `canSendCampaigns(actor.role, settings.reception_can_send_campaigns === true)`
  else `'forbidden'`.
- Schema `campaignFormSchema` (filter fields + `title≤120?`, `templateKey`, `customBody≤1000?`, `libraryItemId≤64?`,
  `languageMode`, `fixedLanguage`, `deliveryMode`, `expiresAt` date-or-empty, `acknowledgedGroupWarning` checkbox).
- `libraryItemId` set → loads item; missing **or inactive** → `'notFound'`; its `body` becomes `customBody`,
  `templateKey:='CUSTOM'`, `libraryItemId` stored on campaign.
- `templateKey==='CUSTOM'` && empty body → `validationError {customBody:'required'}`; body re-validated →
  `validationError {customBody: messageRequired|messageTooLong|unknownVariable}`.
- `finalTitle = trim(title) || titleFromBody(customBody) || templateKey || 'Message'`.
- `deliveryMode==='GROUP'`: `groupAllowed(filter, OWNER && group_sms_allow_for_filtered)` false → `'groupNotAllowed'`;
  `!acknowledgedGroupWarning` → `'groupWarningRequired'`.
- `expiresAt = value ? new Date(value + 'T23:99…' → literally `${value}T23:59:00.000Z`) : null`.
- Creates `Campaign {status:'ACTIVE', …}` then `materializeCampaign` (one `MessageTask` per eligible recipient,
  `sourceType:'CAMPAIGN'`, `sourceId=campaignId`, `dueAt=now`, `sendBy:null`; GROUP also pre-creates `CampaignBatch`
  rows). Over cap → campaign `status:'CANCELLED'`, return `'campaignOverCap'` (tasks already created are left but the
  campaign is cancelled).
- Library use: `useCount: {increment:1}, lastUsedAt: now`.
- Audit `Campaign`/`CAMPAIGN_ACTIVATED`, `new:{title, recipients: created, deliveryMode, batches, filter}`.
- Returns `{id, created, batches}`. **Not idempotent** — a double submit creates two campaigns (UI disables the submit
  while `pending`).

### 2.11 `cancelCampaignAction` ("Cancel remaining")
- Roles: `MESSAGE_ROLES` (no extra owner gate; UI shows the button when `canSendCampaigns(role, true)`).
- Missing → `'notFound'`; `CANCELLED|COMPLETED` → `ok()` (idempotent).
- Single transaction: tasks `PENDING|OPENED` → `CANCELLED`; batches `PENDING` → `SKIPPED` with
  `handledBy, handledAt, skipReason:'cancelled'`; campaign → `CANCELLED`.
- Audit `Campaign`/`CAMPAIGN_CANCELLED` (no payload).

### 2.12 `acknowledgeGroupCopyAction`
- Roles: `MESSAGE_ROLES`. Reads `campaignId` (may be blank → entityId `'unknown'`).
- Audit `Campaign`/`CAMPAIGN_COPY_ALL_ACKNOWLEDGED`. Always `ok()`; no validation, no state change.

### 2.13 `saveMessageDraftAction` (upsert; manual "Save draft" button)
- Roles: `MESSAGE_ROLES`. `messageDraftSchema` (`id?≤64, title?≤120, body 1..1000, language en|am`) +
  `validateMessageBody` → `validationError {body: key}`.
- With `id`: updates only when the draft is owned (`findFirst {id, createdBy: actor.id}`) — a non-owned/foreign id is
  treated as **create-new** (not `'notFound'`). Without id → create.
- `title` stored as `null` when blank. Returns `{id}`.
- Audit `MessageDraft`/`MESSAGE_DRAFT_UPDATED` or `MESSAGE_DRAFT_CREATED` (no payload).

### 2.14 `deleteMessageDraftAction`
- Roles: `MESSAGE_ROLES`. Owned draft only (`createdBy: actor.id`) else `'notFound'`. **Hard delete.**
- Audit `MessageDraft`/`MESSAGE_DRAFT_DELETED`.

### 2.15 `saveLibraryItemAction`
- Roles: `MESSAGE_ROLES` (UI hides the form for non-OWNER, but the action itself is `MESSAGE_ROLES` — owner-gate is
  UI-only). `libraryItemSchema` (`id?≤64, title?≤120, body 1..1000, language, category, isFavorite?`) + body validation.
- `hash = messageBodyHash(body)` (FNV-1a 8-hex over trim/collapse/lowercase); `title = trim(title) || titleFromBody(body)`.
- Duplicate guard: row with `{normalizedHash, language}` that is `active` and `id !== values.id` → `'libraryDuplicate'`;
  DB unique-violation (P2002) also → `'libraryDuplicate'`.
- Insert **or** update sets `{title, body, language, category, normalizedHash, isFavorite, active:true}` (an edit
  re-activates a previously archived item). Update of missing id → `'notFound'`.
- Audit `MessageLibraryItem`/`MESSAGE_LIBRARY_CREATED` (`new:{title, language}`) or `MESSAGE_LIBRARY_UPDATED` (no
  payload). Returns `{id}`.

### 2.16 `toggleLibraryFavoriteAction`
- Roles: `MESSAGE_ROLES`. Flips `isFavorite`; missing → `'notFound'`. **No audit** (despite docs listing
  `LIBRARY_ITEM_FAVORITED` — see §6.34).

### 2.17 `deleteLibraryItemAction` — soft delete
- Roles: `MESSAGE_ROLES`. Missing → `'notFound'`; already inactive → `ok()` (idempotent); else `active:false`.
- Audit `MessageLibraryItem`/`MESSAGE_LIBRARY_ARCHIVED`. Rows are never hard-deleted.

### 2.18 `saveAudienceAction`
- Roles: `MESSAGE_ROLES` (UI shows it only when `canManageAudiences` = OWNER). `audienceFormSchema` =
  `recipientFilterSchema.extend({name: 1..120})`; `audienceId` read separately (trimmed).
- With id: missing → `'notFound'`; update `{name, filterJson}` (no ownership check) → audit `Audience`/`AUDIENCE_UPDATED`.
- Create → `{name, filterJson, createdBy}` → audit `Audience`/`AUDIENCE_CREATED`, `new:{name}`. Returns `{id}`.

### 2.19 `deleteAudienceAction`
- Roles: `MESSAGE_ROLES`. Missing → `'notFound'`; **hard delete**; audit `Audience`/`AUDIENCE_DELETED`.
  (No component in scope calls it — keep for API parity.)

### 2.20 `setCampaignBatchStatusAction`
- Roles: `MESSAGE_ROLES`. Fields: `batchId`, `status` (`SENT`|`SKIPPED`), `reason` (trimmed, hard-sliced to 300).
- Missing → `'notFound'`; batch not `PENDING` → `'batchAlreadyHandled'`.
- `SENT`: transaction → batch `{SENT, handledBy, handledAt}` + its tasks `PENDING|OPENED` → `{SENT, handledBy, handledAt}`.
- `SKIPPED`: empty reason → `validationError {reason:'required'}`; transaction → batch `{SKIPPED, handledBy, handledAt,
  skipReason}` + tasks `PENDING|OPENED` → `{SKIPPED, skipReason, handledBy, handledAt}`.
- Any other status → `validationError {status:'invalid'}`.
- Audit `CampaignBatch`/`CAMPAIGN_BATCH_HANDLED`, `new:{status, reason?}` (reason omitted when empty).

### 2.21 `searchPatientsAction`
- Roles: `MESSAGE_ROLES`. `query` (string). Returns `PickablePatient[]` = active patients matching (case-insensitive
  `fullName contains` OR `phoneE164 contains`; empty query → all active), `orderBy fullName asc`, **take 20**. No audit.

## 3. Queries

All in `queries.ts`; `decorate()` enriches task rows with `sourceAt` (appointment `startsAt` via
`sourceId.split('#')[0]`, or follow-up `dueDate`). `TASK_SELECT` shape = `MessageTaskRow` (§ type at top of file).

- **`listOutbox(now)`** — settings timezone (`timezoneFrom`), `todayKey = clinicDateKey(now,tz)`,
  `dayStart = combineDateAndTime(todayKey,'00:00',tz)`, `dayEnd = endOfDay(now,tz)`.
  Where: `(status in [PENDING,OPENED] AND dueAt < dayEnd) OR status=BLOCKED OR (status in [SENT,SKIPPED] AND
  handledAt >= dayStart AND handledAt < dayEnd)`. `orderBy dueAt asc`, **take 500**.
  Grouping (in order): `BLOCKED → blocked`; `SENT|SKIPPED → doneToday`; `dueAt < dayStart → overdue`;
  `dueAt <= now → dueNow`; else `laterToday`. (CANCELLED/EXPIRED never appear.)
- **`countDueTasks(now)`** — `status in [PENDING,OPENED] AND dueAt <= now` (badge + ticker + layout count).
- **`countDueToday(now)`** — `{dueToday: PENDING|OPENED ∧ dayStart≤dueAt<dayEnd, overdue: PENDING|OPENED ∧ dueAt<dayStart}`
  (dashboard banner).
- **`getMessageTask(id)`** — single row, same select/decorate; `null` if missing.
- **`listPatientMessages(patientId)`** — all statuses, `orderBy createdAt desc`, **take 200**.
- **`countPatientMessages(patientId)`** — plain count (tab badge).
- **`expiredReport()`** — `status='EXPIRED'`, `orderBy expiresAt desc`, **take 500**; grouped by
  `clinicDateKey(expiresAt ?? dueAt, tz)`; groups sorted **dayKey desc**; each `{dayKey, count, tasks}`.
- **`listCampaigns()`** — `orderBy createdAt desc`, **take 100**; per-campaign `groupBy status` counts:
  `total = Σ`, `sent = SENT`, `pending = PENDING+OPENED`, `skipped = SKIPPED`,
  `failed = BLOCKED+EXPIRED+CANCELLED` (failed is computed but only sent/pending/skipped/total are displayed).
- **`getCampaign(id)`** — same select+counts or `null`.
- **`listCampaignQueue(campaignId)`** — `orderBy [{status:'asc'},{dueAt:'asc'}]`, **take 500**; rows
  `{id, patientName, patientPhoneE164, renderedMessage, dueAt, status, blockedReason}`.
- **`listTemplates()`** — all templates, `orderBy [{key:'asc'},{language:'asc'}]`, no cap. `getTemplate(id)` single.
- **`countOptedOutPatients()`** — `patient.doNotMessage === true` count (dashboard elsewhere).
- **`listDrafts(createdBy)`** — owner-scoped, `orderBy updatedAt desc`, **take 100**. `getDraft(id)` single.
- **`listLibraryItems(opts)`** — default `active:true` (unless `includeArchived`), optional `language`, `category`,
  `isFavorite` (when `favorites`), case-insensitive `title OR body contains` (when `search`);
  `orderBy [{isFavorite:'desc'},{useCount:'desc'},{updatedAt:'desc'}]`, **take 200**.
- **`listAudiences()`** — `orderBy name asc`, **take 100**.
- **`listCampaignBatches(campaignId)`** — batches `orderBy batchNumber asc`, **take 50**; per batch `tasks take 5`
  (sample + shared `message` = first task's `renderedMessage`); phones loaded in a second query for **all** tasks of
  those batches (no cap), preserving task order per batch. Row: `{id, batchNumber, size, status, phones[], message,
  sample[]}`.

## 4. Role matrix (page/action/API × OWNER/RECEPTION/STAFF)

Constants (`roles.ts`): `MESSAGE_ROLES = ['OWNER','RECEPTION']` (view/send prepared messages);
`MESSAGE_OWNER_ROLES = ['OWNER']` (templates, jobs, reports). Predicates: `canEditTemplates`/`canManageLibrary`/
`canManageAudiences`/`canOverrideGroupFilter` = `role==='OWNER'`; `canSendMessages` = OWNER|RECEPTION;
`canSendCampaigns(role, receptionAllowed)` = OWNER → true; RECEPTION → `receptionAllowed` (setting
`reception_can_send_campaigns`, default `false`); else false. **STAFF is never in `MESSAGE_ROLES`** → pages redirect
`/403`, actions return `'forbidden'`.

| Surface | OWNER | RECEPTION | STAFF |
|---|---|---|---|
| Pages `/messages`, `/new`, `/campaigns`, `/campaigns/new`, `/campaigns/[id]`, `/drafts`, `/library` | ✔ | ✔ | ✘ (403) |
| Pages `/messages/templates`, `/messages/reports` | ✔ | ✘ (403) | ✘ (403) |
| Task actions (open/confirm/postpone/skip/edit, schedule-change) | ✔ | ✔ | ✘ `forbidden` |
| `previewCampaignAction`, `searchPatientsAction`, draft/library/batch/audience actions, `cancelCampaignAction`, `acknowledgeGroupCopyAction` | ✔ | ✔ | ✘ `forbidden` |
| `createCampaignAction` | ✔ | ✔ **iff** `reception_can_send_campaigns` else `forbidden` | ✘ `forbidden` |
| `saveMessageTemplateAction`, `runMessageJobsAction` | ✔ | ✘ `forbidden` | ✘ `forbidden` |
| UI-only gates: "Archive to library"/library add+delete/form, audience save fields, "Reports" link, `RunJobsButton`, group-filter override (`allowGroupOverride`) | ✔ | ✘ (hidden, actions still open) | — |
| UI campaign gates: wizard `canSend` (`campaignsForbidden` panel), "New campaign" link, campaign detail `canManage` (Cancel remaining) | ✔ | ✔ (detail-page `canSendCampaigns(role,true)` → RECEPTION always true here; create gate uses the setting) | — |
| `GET /api/messages/count` | ✔ (session) | ✔ (session) | ⚠ returns count too — **no role check** (only rendered for OWNER/RECEPTION) |
| `GET /api/messages/export` | ✔ | ✔ | ✘ 403 (or redirect `/change-password`) |
| `POST /api/jobs/messages` | bearer only (no session/role concept) | — | — |

## 5. Validation schemas (fields, rules, error keys)

Zod schemas carry i18n **keys** as messages; `validate()` → `ValidationError.fields = {path: key}` rendered as
`t('validation.' + key)`. Date regex `^\d{4}-\d{2}-\d{2}$` (`'invalidDate'`); checkbox accepts `boolean | 'true'|'false'`.

| Schema | Fields (rule → key) |
|---|---|
| `templateSaveSchema` | `id ≤64 ('tooLong')`; `key ∈ TEMPLATE_KEYS ('invalidTemplate')`; `language ∈ ['en','am'] ('invalidLanguage')`; `body trim 1..1000 ('required'/'tooLong')`; `active` checkbox |
| `taskSendSchema` | `id 1..64 ('required'/'tooLong')` |
| `taskSkipSchema` | `id` as above; `reason trim 1..300 ('required'/'tooLong')` |
| `taskEditSchema` | `id` as above; `message trim 1..1000 ('required'/'tooLong')` |
| `recipientFilterSchema` | `tags[] ≤40 each, ≤20 ('tooMany')`; `tagMode ∈ ANY\|ALL`; `serviceIds[] ≤64 each, ≤50 ('tooMany')`; `serviceCategory ∈ ''\|SERVICE_CATEGORIES`; `language ∈ ''\|en\|am`; `lastVisitFrom/To`, `boughtServiceFrom/To` = `''` or date; `upcoming ∈ ANY\|HAS\|NONE`; `birthdayMonth` int 0..12; `handPickedIds[] ≤64 each, ≤1000 ('tooMany')`; `presetRange ∈ ['',last30,last90,last180,thisYear]` (enum → key is the raw value; use `'invalid'`) |
| `campaignFilterSchema` (legacy normaliser only) | subset of the above (no serviceIds/bought/handPicked/preset) |
| `campaignFormSchema` | recipientFilter + `title ≤120?`; `templateKey ∈ TEMPLATE_KEYS ('invalidTemplate')`; `customBody trim ≤1000?`; `libraryItemId trim ≤64?`; `languageMode ∈ PATIENT\|FIXED`; `fixedLanguage ∈ en\|am`; `deliveryMode ∈ INDIVIDUAL\|GROUP`; `expiresAt` `''`\|date; `acknowledgedGroupWarning` checkbox |
| `messageDraftSchema` | `id? trim ≤64`; `title? trim ≤120`; `body trim 1..1000`; `language ∈ en\|am` |
| `libraryItemSchema` | `id? ≤64`; `title? ≤120`; `body trim 1..1000`; `language`; `category ∈ REMINDER\|GREETING\|ANNOUNCEMENT\|FOLLOWUP\|OTHER`; `isFavorite?` checkbox |
| `audienceFormSchema` | recipientFilter + `name trim 1..120 ('required'/'tooLong')` |

Post-schema body validation (`validateMessageBody` → `fail('validationError', {body: <key>})`):
`messageRequired` (empty after trim), `messageTooLong` (>1000), `unknownVariable` (any `{x}` not in
`name,date,time,doctor,clinic,review_link,reopen_date`). Template save additionally uses `validateTemplateBody`
(same `unknownVariable` key, no length/empty check beyond schema). Limits: `MAX_TEMPLATE_BODY=1000`,
`MAX_SKIP_REASON=300`, `MAX_CAMPAIGN_TITLE=120`, `MAX_CAMPAIGN_MESSAGE=1000`.

## 6. Business rules & invariants (numbered, exact)

1. **No automatic sending.** Only the `sms:`-link provider ships. `buildSmsLink(phone, body, platform)`:
   `android`/`other` → `sms:<phone>?body=<encodeURIComponent(body)>`; `ios` → `sms:<phone>&body=…`
   (`&` separator). Empty/whitespace body **throws** (`'Cannot build an SMS link with an empty body'`).
   Platform from `detectPlatform(userAgent)`: `/iphone|ipad|ipod/i` → `ios`, `/android/` → `android`, else `other` (→ Android form).
2. **Group link**: `buildGroupSmsLink(phones, body, platform)` = `sms:` + `phones.filter(trim non-empty).join(',')` +
   same separator + `body=`; throws when no recipients or empty body. One shared body (first recipient's rendering).
3. **"Did you send?" flow (Outbox)**: `Send`/`Send next` → `openMessageTaskAction`; if result `BLOCKED` the modal
   opens with the blocked reason and **no** link; else `window.location.href = buildSmsLink(...)` and
   `awaitingReturn=true`. A `visibilitychange → visible` listener clears `awaitingReturn` (return-to-tab prompt).
   Modal always offers `copyMessage`, `copyNumber`, `edit` (opens editor), `openLink` (re-opens the `sms:` link),
   and the confirm panel `confirmDone` with `yesSent` / `notYet` / `skip`. `yesSent` → confirm action → full
   `window.location.reload()`; `notYet` → postpone (no reload); skip opens sub-modal with required `skipReason`
   (skip button disabled while blank) → skip action → reload.
4. **Status transitions**: `PENDING →(open)→ OPENED | BLOCKED(reason)`; `OPENED →(confirm)→ SENT`;
   `OPENED →(postpone)→ PENDING`; `* →(skip, from any non-SENT)→ SKIPPED(reason)`; jobs:
   `PENDING|OPENED →(expiresAt < now)→ EXPIRED`; hooks/cancel: `PENDING|OPENED → CANCELLED`;
   opening a `CANCELLED`/`SENT`/`SKIPPED` task fails with `'taskAlreadyHandled'`. `confirm` (server) has no
   status guard besides "not SENT" — it can force any status to `SENT`.
5. **BLOCKED reason keys** (order matters — first hit wins, `eligibility.ts`): `inactive` (patient not active) →
   `doNotMessage` (opted out) → `invalidPhone` (`isValidPhone` E.164 Ethiopian mobile) → `sourceCancelled`
   (appointment/follow-up status `CANCELLED`). Shown in UI via `BLOCKED_REASON_LABEL_KEYS` →
   `blockedInactive`/`blockedDoNotMessage`/`blockedInvalidPhone`/`blockedSourceCancelled`
   (`blockedNoConsent` exists in the catalog but is unreachable).
6. **Idempotent task creation**: `createTask` relies on `@@unique([sourceType, sourceId, templateKey, patientId])`;
   duplicates return `'exists'` without touching the row. Reschedule intentionally mints new rows via versioned
   `sourceId = ${appointmentId}#${startsAt.getTime()}`; schedule-change uses `#sc#${Date.now()}`.
7. **Drafts**: private per user (`createdBy`), listed/loaded only for the author, hard-deletable, `title` nullable
   (UI shows `draftsUntitled`). **No autosave timer** — persistence is the explicit step-2 `saveDraft` button
   (schema comment says "autosaved", code does not debounce; the wizard keeps `draftId` in state so a second save
   updates). Loading a draft (`?draft=` or reuse list) switches `templateKey` to `CUSTOM` and jumps to step 2.
8. **Library duplicate guard**: unique `(normalizedHash, language)` over normalized body (trim, collapse whitespace,
   lowercase; FNV-1a 32-bit → 8 lowercase hex). A *active* duplicate (other id) → `'libraryDuplicate'`; inactive
   duplicates don't block and saving re-activates (`active:true`). Deactivate = `active:false` only, **never delete**
   (historical campaigns keep their `libraryItemId` FK). Auto-title: first non-empty line, truncated to 40 chars with `…`.
9. **Cap enforcement**: `campaign_max_recipients` (default 300; settings form clamps 1..300) is read from the
   settings map at preview **and** materialise time. `eligible.length > cap` → preview `overCap:true` (UI shows
   `overCap`/`campaignOverCap`) and `createCampaignAction` cancels the just-created campaign + returns
   `'campaignOverCap'`.
10. **Campaign progress**: `total`, `sent=SENT`, `pending=PENDING+OPENED`, `skipped=SKIPPED`,
    `failed=BLOCKED+EXPIRED+CANCELLED`. Progress bar `pct = total ? round(sent/total*100) : 0`; caption
    `t('progress', {sent, total, skipped})` = `"{sent} of {total} sent · {skipped} skipped"` on list + detail.
11. **Group gating**: `groupBlockReasons(filter)` → `[]` when no criteria or "plain visited-between" (only a
    `lastVisit*` range ± preset `last30|last90|last180`, no tags/services/category/language/bought/upcoming/birthday,
    no hand-picked); otherwise reasons `service`,`tag`,`category`,`upcoming`,`purchase`, fallback `filtered`.
    `groupAllowed(filter, allowFiltered)` = reasons empty OR `allowFiltered`; `allowFiltered` requires **OWNER** +
    setting `group_sms_allow_for_filtered === true`. Wizard disables GROUP submit until a preview exists, no block
    reasons, and the ack checkbox is ticked; server re-checks (`'groupNotAllowed'`, `'groupWarningRequired'`).
12. **Copy-all-numbers warning**: individual campaigns show `copyAllNumbers`; modal shows `groupWarning` + checkbox
    `acknowledge`; Copy button disabled until ticked; on click: clipboard `pending.map(phone).join(', ')`, then
    `acknowledgeGroupCopyAction` (audit `CAMPAIGN_COPY_ALL_ACKNOWLEDGED`), toast `copied` (2 s).
13. **Group batches**: `resolveGroupBatchSize(group_sms_max_recipients)` → floor, clamp 1..100, non-numeric → 15
    (setting form clamps 1..100, default 15). `planBatches(count, size)` fills earlier batches first
    (40@15 → `[15,15,10]`; ≤0 → `[]`). Batch tasks get `campaignBatchId`; `dueAt = campaign creation time`.
14. **Batch handling**: opening a batch link does **not** change state; queue shows `groupBatchConfirm` and requires
    `yesSent` → batch+tasks `SENT`, or `skip` with reason → `SKIPPED`. Batch not `PENDING` →
    `'batchAlreadyHandled'`. Batch status pills use `batchPending`/`batchSent`/`batchSkipped` — **these keys are
    missing from both locale catalogs** (raw keys render; add them in Laravel).
15. **Campaign cancel**: cancels `PENDING|OPENED` tasks + skips `PENDING` batches (`skipReason:'cancelled'`) +
    cancels campaign; idempotent on `CANCELLED|COMPLETED`.
16. **Expiry**: `expireTasks(now)` flips `PENDING|OPENED` with non-null `expiresAt < now` to `EXPIRED`
    (runs inside `runMessageJobs`). Reminder tasks expire at appointment start; birthdays at next local midnight;
    no-show 72 h; recalls 30 d (see `schedule.ts` / messaging-core). EXPIRED rows surface only in the OWNER report.
17. **Opt-out**: `cancelPendingForPatient(patientId)` sets all `PENDING|OPENED` → `CANCELLED` (used by the patient
    stop-messages flow elsewhere); `open` re-checks `doNotMessage` and blocks even if a task was already open.
18. **`runMessageJobs` summary**: `{expired, sameDay, birthdays, thankYous, followUps, recalls, digestSent}`;
    step order = expire → sameDay → birthdays → thankYous → followUps → recalls → digest. Each generator is
    idempotent via the unique tuple (`RECALL` sourceId `${patientId}#${YYYY-MM}`,
    `BIRTHDAY` `${patientId}#${todayKey}`); same-day batch cap `take 500`, birthday/recall patient scan `take 1000`.
19. **Job endpoint `POST /api/jobs/messages`**: `CRON_SECRET` unset (`z.string().min(16).optional()`) →
    **503** `{ok:false, error:'disabled'}`; `Authorization` header ≠ `Bearer <secret>` (exact string compare) →
    **401** `{ok:false, error:'unauthorized'}`; else **200** `{ok:true, summary}` with `Cache-Control: no-store`.
    Repeated calls are safe (idempotent generation; the run summary counts only newly created rows; digest guarded).
    Never log or echo the secret.
20. **Digest email** (inside job run): requires `digest_enabled === true` **and** non-empty trimmed `digest_email`;
    runs only when `now ≥ today 16:00` clinic time **and** setting `digest_last_sent !== todayKey`. Counts
    `PENDING|OPENED` with `dueAt < endOfDay`; subject `` `Vision Eye Clinic — ${due} message(s) still unsent` ``,
    body `` `${due} prepared message(s) are still waiting to be sent from the clinic phone.` ``; then upserts
    `digest_last_sent = todayKey`; returns `digestSent:true`. Provider is `ConsoleEmailProvider` (logs only in
    non-test; no real send in this build).
21. **Messages badge poll**: `MessagesTicker` fetches `/api/messages/count` (`cache:'no-store'`) immediately and every
    **60 000 ms**; on `res.ok` sets `document.title = count>0 ? `(${count}) ${baseTitle}` : baseTitle`; errors
    ignored. Server: session only (401 when absent), `count = countDueTasks().catch(() => 0)`, `no-store`.
    The sidebar badge is a separate SSR count in the protected layout (`OWNER|RECEPTION` only).
22. **Outbox caps/sorts**: one query ≤500 rows sorted `dueAt asc`; groups preserve that order; "Send next" queue =
    `[overdue, dueNow, laterToday]` (flattened) → first element. Done/blocked sections only render when non-empty;
    empty state message `noMessages` when queue + done + blocked are all empty.
23. **Timezone discipline**: all day boundaries use the clinic timezone setting (`timezoneFrom`, default
    `Africa/Addis_Ababa`); `expiresAt` date input is interpreted as `YYYY-MM-DDT23:59:00.000Z`.
24. **Title fallback chain** (campaign): trimmed title → first line of body (≤40 + `…`) → templateKey → `'Message'`.
    Wizard's client-side placeholder preview uses first line `.slice(0,120)`.
25. **Sensitive terms** (`SENSITIVE_TERMS`: diagnosis/diagnosed/treatment/prescription/medicine/medication/surgery/
    cataract/glaucoma/price/birr/etb/discount + ምርመራ/ህክምና/መድኃኒት/ቀዶ/ዋጋ/ብር) are **scanned but never surfaced** in
    this build (`sensitiveContentWarning` key is unused); they never block. Keep the scan + a warning in Laravel.
26. **Audience apply**: wizard loads `filterJson` through `normalizeRecipientFilter` (defensive; bad JSON →
    empty filter) and re-populates the form; `handPickedIds` become placeholder rows (`fullName=id`, `phoneE164:''`).
27. **Preview is mandatory for group**: selecting GROUP radio or entering step 3 with GROUP fires
    `previewCampaignAction`; GROUP submit disabled while `!preview || groupBlocked || !ack`.
28. **`/messages/new` creates a campaign too**: both wizard pages submit `createCampaignAction` and, on success,
    `router.push('/messages/campaigns/{id}')` — the "new message" flow ends on the campaign detail page.
29. **Patient search**: Enter key or button; results max 20; toggles add/remove (`pickAdd`/`pickRemove`);
    count chip `handPickedCount`.
30. **Export audit** (see §7) and **action audits** never block the business op (`writeAudit` swallows failures,
    stores only diffed `old/new`, redacts secrets, captures IP).
31. **Idempotency summary for tests**: `confirm` re-send → `ok()` no duplicate audit; `cancelCampaign` re-run → `ok()`;
    `deleteLibraryItem` on archived → `ok()`; `setCampaignBatchStatus` on handled → `'batchAlreadyHandled'`;
    `createTask` dup → `'exists'`; `createCampaignAction` **not** idempotent; `saveDraft`/`saveLibrary` upsert by id.
32. **Docs vs code (resolved toward code)**: docs list audit actions `DRAFT_UPSERTED`,
    `LIBRARY_ITEM_CREATED/HIDDEN/FAVORITED`; actual emitted strings are
    `MESSAGE_DRAFT_CREATED|UPDATED|DELETED`, `MESSAGE_LIBRARY_CREATED|UPDATED|ARCHIVED`, and **no** favorite audit.
33. **Audience/library owner-gates are UI-only**: the underlying actions accept any `MESSAGE_ROLES` actor — Laravel
    should enforce OWNER server-side for library add/edit/archive, audience save/delete, template save (templates
    already OWNER server-side) to match the documented matrix.
34. **Missing i18n keys in catalogs** (render as raw key today): `messages.notFound`, `messages.taskAlreadyHandled`,
    `messages.forbidden`, `messages.validationError` (action errors hit `t()` in wizard toasts), and
    `messages.batchPending|batchSent|batchSkipped`. Outbox/CampaignQueue toasts print `result.error` **untranslated**.
35. **`searchPatientsAction`/`listOutbox`/counts are read-only, unaudited**; only downloads are audited (`EXPORTED`).

## 7. Export/CSV

`GET /api/messages/export?format=csv|xlsx` (invalid/absent → `csv`). Guard `requireRole(MESSAGE_ROLES)`:
`PASSWORD_CHANGE_REQUIRED` → `Response.redirect(/change-password)`; other `AuthorizationError` → **403** `Forbidden`.
Rows: today's `listOutbox()` flattened in bucket order `dueNow, laterToday, overdue, doneToday, blocked`, then sorted
by `dueAt` ascending. Columns (exact header order):
`group, dueAt, handledAt, status, patient, patientPhone, template, sourceType, language, message`
— dates formatted with `formatInTz(…, clinicTz)` (empty string when `handledAt` null), `template` = enum key,
`message` = `renderedMessage`. Filename `messages-YYYY-MM-DD.csv` (today's ISO date; `.xlsx` variant for xlsx);
`Content-Type: text/csv; charset=utf-8` (CSV, RFC-4180 + BOM) or
`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`; `Cache-Control: no-store`.
Audit before streaming: `entity:'messageTask'`, `entityId:'export'`, `action:'EXPORTED'`,
`new:{group:'today', format}`. UI: `ExportLinks base="/api/messages/export"` (labels `export.formatCsv`/`formatXlsx`).

## 8. i18n keys used (complete list)

Catalogs `messages/en.json` + `messages/am.json`, namespace **`messages`** (224 keys each, full parity).

**Referenced by code in scope** (190): page/section headings & links: `title subtitle newMessage newMessageLink
backToMessages backToOutbox backToCampaigns templatesLink templatesTitle campaignsLink campaignsTitle newCampaign
reportsLink reportsTitle reportsSubtitle draftsLink draftsTitle draftsSubtitle libraryLink libraryTitle librarySubtitle
noMessages noCampaigns noExpired notSentCount campaignsForbidden`;
outbox controls: `sendNext send open openLink copyMessage copyNumber copied sendFromClinicPhone edit editMessage save
skip skipReason confirmDone yesSent notYet close due groupOverdue groupDueNow groupLaterToday groupDoneToday
groupBlocked`;
status/source/blocked labels: `statusPending statusOpened statusSent statusSkipped statusExpired statusCancelled
statusBlocked sourceAppointment sourceFollowUp sourceBirthday sourceRecall sourceCampaign sourceManual
blockedInactive blockedDoNotMessage blockedInvalidPhone blockedSourceCancelled`;
template labels & editor: `template tplApptReminder tplApptConfirmation tplApptSameDay tplNoShow tplScheduleChange
tplFollowupDue tplRecall tplGlassesReady tplThankYou tplBirthday tplWelcome tplClosureNotice tplCustom
languageMode languagePatient languageFixed active preview characters parts multiPartWarning unknownVariables
templateSaved amDraft templateSafetyNote`;
wizard step 1: `filterTitle filterTags tagsHint filterTagMode tagAny tagAll filterLanguage any filterUpcoming
hasUpcoming noUpcoming filterLastVisitFrom filterLastVisitTo filterCategory filterBirthdayMonth filterServices
filterBoughtServiceFrom filterBoughtServiceTo filterPresetRange presetNone presetLast30 presetLast90 presetLast180
presetThisYear filterHandPicked handPickedHint pickAdd pickRemove searchPlaceholder handPickedCount audiences
applyAudience audienceName saveAudience audienceSaved previewMatched previewEligible overCap`;
wizard step 2: `campaignTitleLabel messageBody saveDraft draftSaved noDrafts loadDraft libraryUse libraryNoItems
archiveToLibrary libraryItemTitle libraryArchive libraryArchived libReminder libGreeting libAnnouncement libFollowup
libOther draftsTitle`;
wizard step 3: `deliverySection deliveryIndividual deliveryIndividualDesc deliveryGroup deliveryGroupDesc groupBlockTitle
groupBlockIntro groupBlockService groupBlockTag groupBlockCategory groupBlockUpcoming groupBlockPurchase
groupBlockFiltered groupBlockOverrideHint groupSafe groupAckText createCampaign progress sendFromClinicPhone`;
campaign detail/queue: `cancelRemaining copyAllNumbers acknowledge groupWarning groupBatchNote sendNextBatch
sendThisBatch groupBatchConfirm batchLabel sampleOf noBatches`;
report/run jobs: `runJobs runningJobs jobsSummary`;
library board: `librarySearch libraryLanguage libraryCategory libraryUse addToFavorites reuseThis usedTimes`;
drafts board: `draftsNoDrafts draftsUntitled`;
error keys reachable via `t(result.error)` or shared UI: `messageRequired messageTooLong unknownVariable
libraryDuplicate campaignOverCap groupNotAllowed groupWarningRequired batchAlreadyHandled` (catalog also has
`sensitiveContentWarning` — unused).
**`common` keys used:** `next back close save saving delete deleted loading all unknownError`.
**`services` keys:** `CATEGORY_LABEL_KEYS[...]` per service category (category filter dropdown).
**`export` keys:** `formatCsv formatXlsx`. **Validation keys** emitted by schemas:
`required tooLong tooMany invalidDate invalidTemplate invalidLanguage invalid` (→ `validation.*`).
**Present in namespace but NOT referenced in this scope** (safe to omit or keep for parity): `blockedNoConsent
builtWith campaignReady consentAlways customMessage deleteAudience deleteDraft deliveryGroupShort
deliveryIndividualShort groupReady messageLanguage messageTitle noAudiences noTemplates ongoingGroups pickNoMatch
servicesHint` + the *missing* keys from §6.34 (`notFound taskAlreadyHandled forbidden validationError batchPending
batchSent batchSkipped` — **create them**).

## 9. Test inventory

Vitest, colocated `*.test.ts`. Re-implement as PHPUnit unit tests (pure) + feature tests (actions below need a DB;
none of the legacy tests hit server actions/queries directly — those were untested; Laravel specs should add them).

- **`index.test.ts`**
  - *template rendering*: `renderTemplate('Hi {name} at {clinic}', …)` → `'Hi Almaz at VEC'`; unknown var stripped
    (`'Hi {nope}'` → `'Hi '`); line containing `{review_link}` dropped when link empty, kept when set;
    `unknownVariables('Hello {name} {bogus}')` → `['bogus']`; `validateTemplateBody` ok/fail.
  - *SMS character counting*: GSM-7 `'hello'`; 160 chars → 1 part, 161 → 2; `{` counts as 2 (extension);
    Amharic `'ሰላም ለአንተ'` → `Unicode` 1 part; 71×`'ሰ'` → 2 parts; `previewTemplate` renders sample `Hemnet Tesfaye`,
    `unknown: []`.
  - *sms: link builder*: `sms:+251911000000?body=hello` (android) / `&body=hello` (ios); Amharic+`\n` percent-encoded
    (`%0A`, no raw newline); `detectPlatform` ios/android/null→`other`; `getSmsProvider('nonsense')` → `MANUAL_LINK`,
    `'GATEWAY'` → `GATEWAY`.
  - *eligibility*: each reason (`inactive`, `doNotMessage`, `invalidPhone`, `sourceCancelled`), clean → `isEligible`.
  - *appointment scheduling (Africa/Addis_Ababa)*: reminder day-before 14:00 expiring at start; late booking due
    immediately; `null` once started; same-day 08:00; no-show next 09:00; thank-you next 09:00; follow-up leads by
    `followup_lead_days`; birthday 08:00 expiring next local midnight.
  - *campaign filters*: `matchesFilter` ANY/ALL tags, category, visit range, upcoming, birthdayMonth;
    `exclusionsFor` reports `doNotMessage/invalidPhone/inactive`, `[]` when clean; `summarizeCandidates` keeps eligible
    ids, counts `excluded.doNotMessage`, `overCap:true` when cap=1.
  - *campaign role rules*: `canSendMessages(RECEPTION)=true`, `(STAFF)=false`;
    `canSendCampaigns('OWNER',false)=true`, `('RECEPTION',false)=false`, `('RECEPTION',true)=true`.
- **`provider.test.ts`**: android `?body=`, ios `&body=`; Amharic/space encoded, no literal space; empty body throws.
  Group link joins with commas + one body; ios uses `&`; blank/whitespace recipients dropped; throws on `[]`,
  `['  ']`, empty body. `detectPlatform` iphone/android/''/null. `ManualLinkProvider.supportsBulkIndividual=false`;
  `getSmsProvider('MANUAL_LINK'|undefined)` → MANUAL, `'GATEWAY'` → GATEWAY with `supportsBulkIndividual=true`.
- **`templates-validation.test.ts`**: `validateMessageBody('   ')` → `{ok:false, error:'messageRequired'}`;
  1001 chars → `'messageTooLong'`; known vars ok, `{does_not_exist}` → `'unknownVariable'` (+ listed in `unknown`);
  clean body `sensitive: []`; flagged body contains `cataract` and `surgery`. `scanSensitiveContent` case-insensitive
  (`GLAUCOMA`→`glaucoma`; `'ዋጋው ብር ነው'`→ contains `ዋጋ`,`ብር`); clean text → `[]`.
- **`message-hash.test.ts`**: `normalizeMessageBody('  Hello   አለም  ')` → `'hello አለም'`; hash stable, case/space
  insensitive, matches `/^[0-9a-f]{8}$/`, differs per body; `titleFromBody` first line when short; 100-char line →
  length 40 ending `…`; empty → `''`.
- **`seed-safety.test.ts`**: every seeded template + library body (EN+AM) contains **no** real service name and none
  of `['diabetes','blood pressure','የስኳር','ደም ግፊት']`.
- **`group-plan.test.ts`**: `planBatches(40,15)=[15,15,10]`; `(7,15)=[7]`; `0/-3 → []`; `(38,15)=[15,15,8]`;
  `(10,0)` → ten 1-sized batches; `(10,1000)=[10]`; `(250,100)` → 3 batches; `(501,1000)` max batch =
  `GROUP_BATCH_HARD_MAX(100)`. `resolveGroupBatchSize(undefined|'oops')=15`; `0→1`, `5000→100`; `15.9→15`.
  `emptyPlan()=[]`.
- **`recipient-filter.test.ts`**: `hasCriteria` empty→false; plain visited-between true, false when any of
  tags/serviceIds/category/upcoming/bought/language set or hand-picked added; `groupBlockReasons` → `['tag']`,
  `['service']`, `['category']`, `['upcoming']`, `['purchase']`, `['filtered']` (language-only), `[]` for visits-only;
  `groupAllowed(f,false)=false` / `(f,true)=true` / visits-only `true` regardless; `matchesRecipientFilter` tag,
  language, serviceId-any, ALL-vs-ANY, bought-range, hand-picked union (empty criteria matches nothing except
  hand-picked), upcoming HAS/NONE; `resolvePresetRange` explicit passthrough, `last90` → `[today-90, today]`,
  `thisYear` → `[YYYY-01-01, today]`; `normalizeRecipientFilter(null|'nope')` → empty filter, coercions
  (tagMode/lang/upcoming/preset, `birthdayMonth:0`, `handPickedIds:[]`); `normalizeLegacyFilter` maps old shape;
  `summarizeRecipients` empty-criteria → `eligible=[]`, counts `matched/eligible/excluded.doNotMessage`,
  `overCap` with cap=1, `groupBlockReasons ['tag'] → []` when override allowed.
- **`render.test.ts`**: `loadTemplateBodies` queries `{where:{active:true}}` and keys `"<key>:<lang>"`; `bodyFor`
  prefers language, falls back `en`, `''` when absent; `renderVariables` statics + `date/time` in clinic tz
  (`'12:00'` for 09:00Z Addis), `doctor/reopen_date` only when provided (date/time then undefined);
  `renderMessage` substitution; `resolveLanguage` am/en, null/undefined/`'fr'` → `'en'`.
- **`schedule.test.ts`** (names + anchors): confirmation due now, expires end of clinic day; reminder `null` once
  started / day-before `reminder_send_time` / falls due immediately for late bookings; same-day 08:00 local with
  late-window fallbacks and `null` after start; no-show next 09:00 valid 72 h; follow-up lead
  `followup_lead_days`, expires +7 d from due date, immediate when window passed; thank-you next morning;
  birthday 08:00 → next local midnight; recall due now 30 d; `immediateSchedule` due now never expires;
  `recallPeriodKey` → `YYYY-MM` in clinic time; `laterOf` returns latest instant.

## 10. UI behavior per page

- **Outbox (`/messages`)**: header `title`/`subtitle`; right side: `newMessageLink` (btn-primary), `ExportLinks`,
  `RunJobsButton` (OWNER only), links to library/drafts/templates/campaigns, reports (OWNER only). Toolbar:
  `sendNext` (disabled when queue empty; sends `queue[0]`) + hint `sendFromClinicPhone`. Sections render only when
  non-empty, in order `groupOverdue, groupDueNow, groupLaterToday, groupDoneToday, groupBlocked` with header
  `label (count)`. Row: name, phone, status pill, `source · template` line, truncated message, red blocked-reason
  line, `due HH:MM` (en-GB, clinic tz); left border red/amber for overdue/due. Action buttons
  (`copyMessage`/`open`/`send`) only for `PENDING|OPENED`. Modals (fixed overlay): task modal (message, 4 utility
  buttons, confirm panel, Close), skip modal (required reason), edit modal (textarea + Save; success updates text
  in place). Toasts bottom-center (`copied` 1.5 s; error text = raw error key).
- **`/messages/new` & `/messages/campaigns/new`**: header + back link; `MessageWizard` with step rail buttons
  `filterTitle | messageBody | deliverySection`. Step 1: card with preset/language/tags/tagMode/visit dates/bought
  dates/upcoming/category/birthday selects & inputs, multi-service picker (chips with ×), hand-picked search
  (Enter/button, `preview` label while searching, add/remove chips, `handPickedCount`), audiences card (apply select;
  name+save only for OWNER), Preview card (`previewMatched`, `previewEligible`, red `overCap`), Next.
  Step 2: `campaignTitleLabel` input (maxLength 120, placeholder = auto-title), template select (distinct template
  rows + `tplCustom`), languageMode select, fixedLanguage select only when FIXED, CUSTOM textarea + language select +
  `saveDraft` (+ `archiveToLibrary` + category select only when `canManageLibrary`), hidden `libraryItemId`/`expiresAt`,
  library reuse list (first 8, `libraryUse`), drafts list (first 8, `loadDraft`), Back/Next.
  Step 3: two radio cards (INDIVIDUAL/GROUP), GROUP → auto preview + amber `groupBlock*` list or green `groupSafe`,
  `groupAckText` checkbox (hidden `acknowledgedGroupWarning=false` when INDIVIDUAL), note card `sendFromClinicPhone`,
  inline error text, submit disabled while `pending` or (GROUP && (!preview || blocked || !ack)), label
  `saving…` while pending else `createCampaign`/`newMessage`; success → push `/messages/campaigns/<id>`.
  `!canSend` → single card `campaignsForbidden`. Toasts 2.5 s for draft/library/audience saves.
- **`/messages/templates`**: OWNER; safety-note banner (`templateSafetyNote`); two-column: scrollable template list
  (label + `en` / `am · needs native review`), editor textarea with live `countSms` line
  (`N characters · M part(s) · GSM-7|Unicode`), amber `multiPartWarning` when parts>1, `active` checkbox,
  red `unknownVariables: a, b` line, preview box (sample data), variable insert chips
  (`{name} {date} {time} {doctor} {clinic} {review_link} {reopen_date}`), Save → toast `templateSaved` 1.5 s
  (error → raw key). Saves post `id/key/language/body/active`.
- **`/messages/campaigns`**: list card rows: title link, status pill (`campaignDraft|Active|Completed|Cancelled`),
  right-aligned `progress`; empty `noCampaigns`; New-campaign button gated by `canSendCampaigns`.
- **Campaign detail `/messages/campaigns/[id]`**: title + status subtitle; `CampaignQueue`: progress card (emerald bar,
  `progress` caption, amber `groupBatchNote` for GROUP), action row — GROUP: `sendNextBatch` (first pending batch)
  else INDIVIDUAL: `sendNext` (first PENDING/OPENED item) + `copyAllNumbers` (hidden for GROUP) + `cancelRemaining`
  (when `canManage`, red). GROUP: batch list (`batchLabel`, status pill, `sampleOf` when size>5 sample, shared
  message, `sendThisBatch` when PENDING); INDIVIDUAL: item list (name/phone/message + status pill + `send` for
  PENDING/OPENED). Modals: task (message + `yesSent`/`skip`/`close`), batch (batchLabel + `groupBatchConfirm` +
  message + `yesSent`/`skip`/`close`), shared skip modal (required reason; routes to batch vs task handler), copy-all
  modal (`groupWarning` + `acknowledge` checkbox, Copy disabled until ticked → clipboard + audit + toast `copied`
  2 s). Confirm/skip/handle → `router.refresh()`.
- **`/messages/drafts`**: subtitle `draftsSubtitle`; empty `draftsNoDrafts`; rows: `title||draftsUntitled` + language
  pill + 2-line body; actions `loadDraft` → `/messages/new?draft=<id>`, `delete` (red) → toast `deleted` 2 s
  (error toast 3 s with raw key).
- **`/messages/library`**: filters card (search input, language select, category select, favorites checkbox);
  client-side filtering over the server list; rows: title + language pill + category pill, full body, `usedTimes`,
  actions `reuseThis` → `/messages/new?library=<id>`, favorite toggle (`★/☆ addToFavorites`), `delete` (OWNER only,
  archives). Empty `libraryNoItems`. OWNER add-card: `libraryItemTitle` input (maxLength 120), body textarea,
  language/category selects, `libraryArchive` button (disabled when body blank) → toast `libraryArchived` or error
  (`libraryDuplicate`).
- **`/messages/reports`**: OWNER; empty `noExpired`; per-day cards `<dayKey> · {count} not sent` (dayKey desc),
  rows: patient name, phone, template label.
- **`RunJobsButton`**: label `runJobs` → while pending `runningJobs`; on success shows `jobsSummary`
  (`{sameDay+birthdays+thankYous+followUps+recalls} created, {expired} expired`) as small text; on failure shows the
  raw error key.
- **`MessagesTicker`** (protected layout): `document.title = "(N) <app name>"` per §6.21; SSR sidebar badge mirrors
  `countDueTasks` for OWNER/RECEPTION.
- **Patient tab `PatientMessagesList`**: empty → `noMessages`; rows: template label, status pill, source label,
  `formatInTz(handledAt ?? dueAt)`, full message, red blocked reason, raw `skipReason` under SKIPPED.
