# Messaging core (pure logic) — Laravel rebuild spec

Source of truth: `src/modules/messaging/{constants,templates,render,schedule,eligibility,campaign-filter,recipient-filter,group-plan,message-hash}.ts` + their tests, `docs/messaging-6b-manual.md`, README checklist 35–40, `prisma/schema.prisma`. Adjacent context read: `roles.ts`, `provider.ts`, `schemas.ts`, `generate.ts` (task lifecycle only). Clinic timezone default: `Africa/Addis_Ababa` (`DEFAULT_TIMEZONE`, UTC+3, no DST). All wall-clock math happens in clinic time; storage is UTC instants.

## 1. Module map (file → responsibility)

| File | Responsibility (pure unless noted) |
| --- | --- |
| `constants.ts` | All registries and label maps: template variables, languages, template keys → i18n keys, status/source/campaign/delivery/batch/library label maps, blocked-reason registry, group-block-reason registry, filter enums, size limits, safety-note key, GSM-7 basic/extension charsets. |
| `templates.ts` | Template-body variable extraction (`{…}` regex), unknown-variable detection, body validation, sample data, `renderTemplate` substitution + `review_link` line-drop, SMS counter (GSM-7 vs Unicode, parts 160/153 vs 70/67), sensitive-term scan, composer `validateMessageBody`. |
| `render.ts` | Per-task rendering: **one impure loader** `loadTemplateBodies()` (Prisma `findMany({where:{active:true}})` → `Map<"KEY:lang", body>`), language fallback `bodyFor`, `renderVariables` (context → variable map incl. date/time formatting), `renderMessage`, `resolveLanguage`. |
| `schedule.ts` | Pure `dueAt`/`sendBy`/`expiresAt` math for every automatic template (reminder, confirmation, same-day, no-show, follow-up, thank-you, birthday, recall, immediate) + `recallPeriodKey` idempotency key + `DEFAULT_MESSAGING_SETTINGS`. |
| `eligibility.ts` | `blockedReason()` / `isEligible()` — active + do-not-message + phone + source-status gate; runs at task creation and again when the operator opens a task. |
| `campaign-filter.ts` | Legacy (pre-6B) audience matcher `matchesFilter`, hard-gate `exclusionsFor`, `summarizeCandidates` preview (matched/eligible/excluded/cap/overCap). |
| `recipient-filter.ts` | Slice 6B richer filter: type + normalisers (legacy upgrade, defensive JSON), preset date ranges, criteria matching, hand-picked union, group-safety rules (`hasCriteria`/`isPlainVisitedBetween`/`groupBlockReasons`/`groupAllowed`), `summarizeRecipients`. |
| `group-plan.ts` | Batch splitting for “One group chat”: hard bounds 1–100, `planBatches(count, size)` (40@15 → `[15,15,10]`), `resolveGroupBatchSize` (default 15), `emptyPlan`. |
| `message-hash.ts` | Message Library duplicate guard: `normalizeMessageBody` (trim/collapse/lowercase), FNV-1a 32-bit `messageBodyHash` → 8 lowercase hex chars, `titleFromBody` auto-title (40 chars + `…`). |
| (adjacent) `roles.ts` | `MESSAGE_ROLES` / `MESSAGE_OWNER_ROLES` / role predicates — §4. |
| (adjacent) `provider.ts` | `sms:` link builders (`?body=` vs `&body=`), platform detection, provider resolution — §5. |
| (adjacent) `schemas.ts` | Zod validation for template save, task send/skip/edit, legacy + 6B filters, campaign form, drafts, library, audiences — §3. |
| (adjacent) `generate.ts` | Idempotent task creation, expiry/cancellation, job orchestration — used only to pin §5/§8 invariants. |

## 2. Pure functions to port

### 2.1 `constants.ts`

- `TEMPLATE_VARIABLES = ['name','date','time','doctor','clinic','review_link','reopen_date']` — the **only** placeholders a body may contain.
- `MESSAGE_LANGUAGES = ['en','am']`; `isMessageLanguage(v): v is 'en'|'am'` → `v === 'en' || v === 'am'`.
- `TEMPLATE_KEYS = ['APPT_REMINDER','APPT_CONFIRMATION','APPT_SAME_DAY','NO_SHOW','SCHEDULE_CHANGE','FOLLOWUP_DUE','RECALL','GLASSES_READY','THANK_YOU','BIRTHDAY','WELCOME','CLOSURE_NOTICE','CUSTOM']` (13, matches Prisma enum `MessageTemplateKey`).
- `TEMPLATE_LABEL_KEYS` (→ `messages.*`): `APPT_REMINDER→tplApptReminder`, `APPT_CONFIRMATION→tplApptConfirmation`, `APPT_SAME_DAY→tplApptSameDay`, `NO_SHOW→tplNoShow`, `SCHEDULE_CHANGE→tplScheduleChange`, `FOLLOWUP_DUE→tplFollowupDue`, `RECALL→tplRecall`, `GLASSES_READY→tplGlassesReady`, `THANK_YOU→tplThankYou`, `BIRTHDAY→tplBirthday`, `WELCOME→tplWelcome`, `CLOSURE_NOTICE→tplClosureNotice`, `CUSTOM→tplCustom`.
- `MESSAGE_STATUS_LABEL_KEYS`: `PENDING→statusPending`, `OPENED→statusOpened`, `SENT→statusSent`, `SKIPPED→statusSkipped`, `EXPIRED→statusExpired`, `CANCELLED→statusCancelled`, `BLOCKED→statusBlocked`.
- `MESSAGE_SOURCE_LABEL_KEYS`: `APPOINTMENT→sourceAppointment`, `FOLLOWUP→sourceFollowUp` (note camel “FollowUp”), `BIRTHDAY→sourceBirthday`, `RECALL→sourceRecall`, `CAMPAIGN→sourceCampaign`, `MANUAL→sourceManual`.
- `CAMPAIGN_STATUS_LABEL_KEYS`: `DRAFT→campaignDraft`, `ACTIVE→campaignActive`, `COMPLETED→campaignCompleted`, `CANCELLED→campaignCancelled`.
- `CAMPAIGN_DELIVERY_MODES = ['INDIVIDUAL','GROUP']`; `DELIVERY_MODE_LABEL_KEYS`: `INDIVIDUAL→deliveryIndividual`, `GROUP→deliveryGroup`.
- `BATCH_STATUS_LABEL_KEYS`: `PENDING→batchPending`, `SENT→batchSent`, `SKIPPED→batchSkipped`.
- `LIBRARY_CATEGORIES = ['REMINDER','GREETING','ANNOUNCEMENT','FOLLOWUP','OTHER']`; label keys `libReminder`, `libGreeting`, `libAnnouncement`, `libFollowup`, `libOther`.
- `GROUP_BLOCK_LABEL_KEYS` = `{ service:'groupBlockService', tag:'groupBlockTag', category:'groupBlockCategory', upcoming:'groupBlockUpcoming', purchase:'groupBlockPurchase', filtered:'groupBlockFiltered' }` (suffixes produced by `groupBlockReasons`).
- `BLOCKED_REASONS = ['inactive','doNotMessage','invalidPhone','sourceCancelled']`; `BLOCKED_REASON_LABEL_KEYS` = `{ inactive:'blockedInactive', doNotMessage:'blockedDoNotMessage', invalidPhone:'blockedInvalidPhone', sourceCancelled:'blockedSourceCancelled' }`.
- `CAMPAIGN_LANGUAGE_MODES = ['PATIENT','FIXED']`; `CAMPAIGN_TAG_MODES = ['ANY','ALL']`; `CAMPAIGN_UPCOMING_MODES = ['ANY','HAS','NONE']`.
- Limits: `MAX_TEMPLATE_BODY = 1000`, `MAX_SKIP_REASON = 300`, `MAX_CAMPAIGN_TITLE = 120`, `MAX_CAMPAIGN_MESSAGE = 1000`.
- `TEMPLATE_SAFETY_NOTE_KEY = 'templateSafetyNote'`.
- `GSM7_BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?' + '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà'` (literal concatenated string, includes `\n` and `\r`).
- `GSM7_EXTENSION = '\f^{}\\[~]|€'` → chars: FF, `^`, `{`, `}`, `\`, `[`, `~`, `]`, `|`, `€` (each costs 2 septets).

### 2.2 `templates.ts`

- `VARIABLE_RE = /\{([^{}]*)\}/g` (module-private; rebuilt per call — no shared lastIndex state).
- `templateVariables(body): string[]` — all `\{([^{}]*)\}` captures, **first-seen order**, de-duplicated (`if (!found.includes(name))`).
- `unknownVariables(body): string[]` — `templateVariables(body)` filtered against `TEMPLATE_VARIABLES`; `[]` ⇒ valid.
- `validateTemplateBody(body): { ok: boolean; unknown: string[] }` — `ok = unknownVariables(body).length === 0`. A stray `{`/`}` is legal literal text (GSM-7 extension chars) because only `{name}`-shaped matches count.
- `SAMPLE_DATA: Record<TemplateVariable,string>` = `{ name:'Hemnet Tesfaye', date:'Mon 12 Oct', time:'10:00', doctor:'Dr. Abebe Kebede', clinic:'Vision Eye Clinic', review_link:'https://g.page/vision-eye-clinic/review', reopen_date:'Mon 19 Oct' }`.
- `renderTemplate(body, data: Partial<TemplateSample>): string`
  1. `reviewEmpty = !data.review_link` (empty string/undefined/missing).
  2. Split on `'\n'`; drop every line where `reviewEmpty && line.includes('{review_link}')`; rejoin with `'\n'`.
  3. Replace each `VARIABLE_RE` match: unknown name → `''`; known name → `data[name] ?? ''` (missing value also `''`).
- `countSms(text): SmsCount`
  1. Walk `[...text]` (Unicode code points). Basic char → `+1`; extension char → `+2`; first non-GSM char → set `encoding='Unicode'` and stop scanning.
  2. If Unicode: `length = [...text].length` (recompute over full string).
  3. `perPart` single: GSM-7 `160`, Unicode `70`. Multipart: GSM-7 `153`, Unicode `67`.
  4. `parts = length===0 ? 1 : length<=perPart ? 1 : Math.ceil(length / multipartPerPart)`.
  5. Return `{ encoding, length, perPart: parts===1 ? perPart : multipartPerPart, parts }` — i.e. `perPart` reports the *applicable* per-part budget (160/70 for 1 part, 153/67 for >1).
  Edge cases: empty string → `{GSM-7, length 0, perPart 160, parts 1}`; `'{'` → `length 2` (extension), parts 1; one Amharic char among Latin flips the whole message to Unicode with UTF-16-safe code-point length.
- `previewTemplate(body, sample = {}): { text, count, unknown }` — merges `{...SAMPLE_DATA, ...sample}`, renders, counts, and reports `unknownVariables(body)` (unknowns reported on the raw body, not rendered output).
- `SENSITIVE_TERMS` (19, checked in this order): `'diagnosis','diagnosed','treatment','prescription','medicine','medication','surgery','cataract','glaucoma','price','birr','etb','discount','ምርመራ','ህክምና','መድኃኒት','ቀዶ','ዋጋ','ብር'`.
- `scanSensitiveContent(body): string[]` — `body.toLowerCase()`, then for each term `if (lower.includes(term.toLowerCase())) hits.push(term)`; returns canonical terms (Latin lowercase as listed; Amharic unchanged) in registry order; `[]` = clean.
- `validateMessageBody(body): MessageBodyCheck` — precedence: (1) `body.trim()` empty → `{ok:false, error:'messageRequired'}`; (2) `trimmed.length > 1000` → `'messageTooLong'`; (3) `unknownVariables(body).length > 0` → `'unknownVariable'`; else ok. Always returns `unknown` and `sensitive`; **sensitive never blocks** (surfaced as warning).

### 2.3 `render.ts`

- `loadTemplateBodies(): Promise<Map<string,string>>` — impure; `prisma.messageTemplate.findMany({ where: { active: true }, select: { key: true, language: true, body: true } })` → `new Map(rows.map(r => [\`${r.key}:${r.language}\`, r.body]))`.
- `bodyFor(bodies, key, language): string` — `bodies.get(\`${key}:${language}\`) ?? bodies.get(\`${key}:en\`) ?? ''` (requested → English → empty).
- `RenderContext = { patientName, language, clinicName, reviewLink, startsAt?: Date|null, doctorName?: string|null, reopenDate?: string|null, timeZone }`.
- `renderVariables(ctx): Partial<Record<TemplateVariable,string>>`:
  - always: `name = patientName`, `clinic = clinicName`, `review_link = reviewLink`;
  - `doctor` only when `ctx.doctorName` truthy; `reopen_date` only when `ctx.reopenDate` truthy;
  - only when `ctx.startsAt`: `date = formatInTz(startsAt, tz, { weekday:'short', day:'numeric', month:'short' }, 'en-GB')` (e.g. `Tue 12 Oct`) and `time = clinicTimeKey(startsAt, tz)` → `HH:mm` clinic-local (e.g. `2026-10-12T09:00Z` → `'12:00'` in Addis).
- `renderMessage(body, ctx)` = `renderTemplate(body, renderVariables(ctx))`.
- `resolveLanguage(preferred)` = `isMessageLanguage(preferred) ? preferred : 'en'` (null/undefined/`'fr'` → `'en'`).

Datetime helpers relied on (port verbatim): `clinicDateKey` → `YYYY-MM-DD` in tz; `clinicTimeKey` → `HH:mm`; `combineDateAndTime(dateKey, timeKey, tz)` → UTC instant (two-pass offset resolution across DST); `dateKeyAdd(dateKey, days)` → pure calendar arithmetic in UTC; `endOfDay(input, tz)` = `combineDateAndTime(dateKeyAdd(clinicDateKey(input),1), '00:00', tz)`; `formatInTz(input, tz, opts, locale='en-GB')`.

### 2.4 `schedule.ts`

`MessagingSettings = { timeZone, reminderSendTime /*"HH:mm"*/, reminderSendByOffsetMinutes, followupLeadDays, recallMonths }`;
`DEFAULT_MESSAGING_SETTINGS = { timeZone:'Africa/Addis_Ababa', reminderSendTime:'14:00', reminderSendByOffsetMinutes:60, followupLeadDays:3, recallMonths:12 }`.
`Schedule = { dueAt: Date, sendBy: Date|null, expiresAt: Date|null }`. `DAY_MS = 86400000`. All `dueAt < now` results below clamp to `now` (`dueAt = now`).

| Function | Signature | Algorithm | Null / edge |
| --- | --- | --- | --- |
| `appointmentConfirmationSchedule` | `(now, settings)` | `dueAt = now`; `sendBy = endOfDay(now, tz)`; `expiresAt = null`. | never null. |
| `appointmentReminderSchedule` | `(appt: {startsAt, createdAt}, settings, now)` | if `startsAt <= now` → `null`. `dueAt = combineDateAndTime(dateKeyAdd(clinicDateKey(startsAt,tz),-1), reminderSendTime, tz)` (day before at 14:00 local). If `createdAt > dueAt` **or** `dueAt < now` → `dueAt = now` (late booking / overdue). `sendBy = new Date(startsAt - max(0, reminderSendByOffsetMinutes)*60000)` (= startsAt −60 min default). `expiresAt = startsAt`. | null once appointment started. |
| `appointmentSameDaySchedule` | `(appt, settings, now)` | if `startsAt <= now` → `null`. `dueAt = combineDateAndTime(clinicDateKey(now,tz), '08:00', tz)`; if `dueAt < now` → `now`. `sendBy = expiresAt = startsAt`. | null once started. |
| `noShowSchedule` | `(now, settings)` | `dueAt = combineDateAndTime(dateKeyAdd(clinicDateKey(now),1), '09:00', tz)` (next morning); `sendBy = null`; `expiresAt = dueAt + 3*DAY_MS` (72 h). | never null. |
| `followUpSchedule` | `(dueDate, settings, now)` | `dueKey = clinicDateKey(dueDate)`; `dueAt = combineDateAndTime(dateKeyAdd(dueKey, -followupLeadDays), '09:00', tz)`; clamp to now. `expiresAt = combineDateAndTime(dateKeyAdd(dueKey, 7), '23:59', tz)` (+7 d local 23:59). | never null. |
| `thankYouSchedule` | `(appt, settings)` | `dueAt = combineDateAndTime(dateKeyAdd(clinicDateKey(startsAt),1), '09:00', tz)` (morning after appointment); `sendBy = null`; `expiresAt = null`. | never null. |
| `birthdaySchedule` | `(now, settings)` | `dueAt = combineDateAndTime(clinicDateKey(now), '08:00', tz)`; `sendBy = null`; `expiresAt = combineDateAndTime(dateKeyAdd(todayKey,1), '00:00', tz)` (next local midnight). | never null. |
| `recallSchedule` | `(now)` | `dueAt = now`; `expiresAt = now + 30*DAY_MS`. | ignores settings. |
| `immediateSchedule` | `(now)` | `{ dueAt: now, sendBy: null, expiresAt: null }` (welcome, schedule-change, manual). | never null. |
| `recallPeriodKey` | `(now, timeZone)` | `clinicDateKey(now, tz).slice(0,7)` → `'YYYY-MM'` clinic-local (rollover at local midnight: `2026-10-31T21:00Z` → `'2026-11'`). | — |
| `laterOf(a,b)` | `(Date, Date) => Date` | `a.getTime() >= b.getTime() ? a : b` (ties → `a`). | — |

### 2.5 `eligibility.ts`

- `EligibilityPatient = { active: boolean, doNotMessage: boolean, phoneE164: string }`; `EligibilityInput = { patient, sourceStatus?: string|null }`.
- `blockedReason(input): BlockedReason | null` — **fixed precedence**: `!patient.active → 'inactive'`; `patient.doNotMessage → 'doNotMessage'`; `!isValidPhone(phoneE164) → 'invalidPhone'`; `sourceStatus === 'CANCELLED' → 'sourceCancelled'`; else `null`.
- `isEligible(input)` = `blockedReason(input) === null`.
- `isValidPhone` (lib) = `normalizePhone(input) !== null`; E.164 Ethiopian mobile only: strip spaces `- . ( )`, then match `^0[97]\d{8}$` → `+251…`, `^[97]\d{8}$` → `+251…`, `^251[97]\d{8}$` → `+…`; `+`-prefixed must match `^251[97]\d{8}$`. Anything else (`'12345'`, `'bad'`) invalid.
- A failure never drops the task — it is stored `BLOCKED` with the reason key (see §8).

### 2.6 `campaign-filter.ts` (legacy matcher)

- `CampaignCandidate = { id, fullName, phoneE164, preferredLanguage, doNotMessage, active, birthDate: Date|null, tags: string[], lastVisitAt: Date|null, lastPurchaseAt: Date|null, hasUpcoming: boolean, categories: string[], serviceIds: string[] }`.
- `matchesFilter(candidate, filter: CampaignFilterValues): boolean` — all criteria AND:
  1. `filter.tags.length > 0` → lowercase both sides; `tagMode==='ALL'` ⇒ every wanted tag ∈ candidate tags; else (`'ANY'`) ⇒ some; miss → false.
  2. `filter.language` non-empty ⇒ `candidate.preferredLanguage === filter.language` (strict).
  3. `lastVisitFrom`/`lastVisitTo` set ⇒ `candidate.lastVisitAt` must exist; key = `lastVisitAt.toISOString().slice(0,10)`; reject if `key < from` or `key > to` (string compare, inclusive bounds).
  4. `upcoming === 'HAS'` ⇒ `hasUpcoming`; `'NONE'` ⇒ `!hasUpcoming`; `'ANY'` free.
  5. `filter.serviceCategory` non-empty ⇒ `candidate.categories.includes(...)`.
  6. `filter.birthdayMonth > 0` ⇒ `birthDate` exists and `birthDate.getUTCMonth()+1 === birthdayMonth` (UTC month, not clinic tz).
  Empty filter ⇒ matches **every** candidate.
- `exclusionsFor(candidate): BlockedReason[]` — hard gates only, in order: `!active → 'inactive'`; `doNotMessage → 'doNotMessage'`; `!isValidPhone → 'invalidPhone'` (never `sourceCancelled`).
- `summarizeCandidates(candidates, filter, cap): { eligible, preview }` — filter → per-candidate `exclusionsFor`; zero reasons ⇒ push to `eligible`, else increment each reason counter. `excluded` is a full record with all 4 keys initialised to `0` (`sourceCancelled` always 0 here). Preview `{ matched: filtered.length, eligible: eligible.length, excluded, cap, overCap: eligible.length > cap }`.

### 2.7 `recipient-filter.ts` (6B)

- `RECIPIENT_PRESET_RANGES = ['', 'last30', 'last90', 'last180', 'thisYear']`.
- `RecipientFilter = { tags: string[], tagMode: 'ANY'|'ALL', serviceIds: string[], serviceCategory: ''|ServiceCategory, language: ''|'en'|'am', lastVisitFrom, lastVisitTo, boughtServiceFrom, boughtServiceTo, upcoming: 'ANY'|'HAS'|'NONE', birthdayMonth: number, handPickedIds: string[], presetRange: RecipientPresetRange }`.
  `ServiceCategory ∈ {'consultation','refraction','diagnostics','tests','glasses','optical','contact_lenses','specialist','surgery','other'}`.
- `emptyRecipientFilter()` — the exact zero shape (all `''`/`[]`/`0`/`'ANY'`/`'ANY'`).
- `normalizeLegacyFilter(values: CampaignFilterValues)` — spread empty + copy `tags, tagMode, language, lastVisitFrom, lastVisitTo, upcoming, serviceCategory, birthdayMonth` (6B-only fields stay empty).
- `normalizeRecipientFilter(input: unknown)` — non-object/null/`'nope'` → empty; `tags`/`serviceIds`/`handPickedIds` = keep only string array items; `tagMode = raw==='ALL' ? 'ALL' : 'ANY'`; `serviceCategory` via `isServiceCategory` else `''`; `language` only `'en'|'am'` else `''`; date fields = string or fallback `''`; `upcoming` only `'HAS'|'NONE'` else `'ANY'`; `birthdayMonth` only if `typeof === 'number'` (no range clamp) else `0`; `presetRange` only if in `RECIPIENT_PRESET_RANGES` else `''`; unknown keys (e.g. `junk`) dropped.
- `resolvePresetRange(filter, todayKey): { lastVisitFrom, lastVisitTo }` — no preset → explicit fields as-is. `thisYear` → `{ lastVisitFrom: todayKey.slice(0,4)+'-01-01', lastVisitTo: todayKey }`. `last30/90/180` → `from = today − {30|90|180} days` via pure UTC arithmetic on `YYYY-MM-DD` (e.g. `2026-10-10` −90 → `2026-07-12`), `to = todayKey`. (Returned range is then fed into the `lastVisit*` criteria.)
- `hasCriteria(filter)` — true if any of: `tags`, `serviceIds` non-empty; `serviceCategory !== ''`; `language !== ''`; `lastVisitFrom|lastVisitTo|boughtServiceFrom|boughtServiceTo !== ''`; `upcoming !== 'ANY'`; `birthdayMonth > 0`; `presetRange !== ''`. **`handPickedIds` alone never counts as criteria.**
- `isPlainVisitedBetween(filter)` — true iff: `tags`, `serviceIds` empty; `serviceCategory === ''`; `language === ''`; `boughtServiceFrom/To === ''`; `upcoming === 'ANY'`; `birthdayMonth === 0`; `presetRange ∈ {'', 'last30','last90','last180'}` (note: `'thisYear'` disqualifies); `lastVisitFrom !== '' || lastVisitTo !== ''`; `handPickedIds.length === 0`.
- `groupBlockReasons(filter): string[]` — `if (!hasCriteria(filter)) return []`; `if (isPlainVisitedBetween(filter)) return []`; then push in this **fixed order**: `'service'` (serviceIds), `'tag'` (tags), `'category'` (serviceCategory), `'upcoming'` (upcoming≠ANY), `'purchase'` (boughtServiceFrom/To); if nothing was pushed → `['filtered']` (e.g. language-only, birthday-only, preset-only). Suffixes map to `messages.groupBlock.*` via `GROUP_BLOCK_LABEL_KEYS`.
- `groupAllowed(filter, allowFiltered): boolean` — `groupBlockReasons` empty ⇒ true; else return `allowFiltered` (the OWNER override `group_sms_allow_for_filtered`).
- `withinRange(dateKey|null, from, to)` — null → false; `from && dateKey < from` → false; `to && dateKey > to` → false; else true.
- `matchesCriteria(candidate, filter)` — same order as `matchesFilter` plus: `serviceIds` non-empty ⇒ `candidate.serviceIds.some(id => filter.serviceIds.includes(id))` (any-overlap); last-visit range now uses `dateKeyOf(lastVisitAt)` (ISO `slice(0,10)`, null → fail) + `withinRange`; **new** bought-range uses `dateKeyOf(lastPurchaseAt)` + `withinRange`; language/category/upcoming/birthday rules identical to `matchesFilter`.
- `matchesRecipientFilter(candidate, filter)` — `filter.handPickedIds.includes(candidate.id)` ⇒ **true** (hand-picked union); `!hasCriteria(filter)` ⇒ **false** (empty filter matches nobody — deliberate difference from legacy `matchesFilter`); else `matchesCriteria`.
- `summarizeRecipients(candidates, filter, cap, allowFilteredGroup)` — as `summarizeCandidates` but through `matchesRecipientFilter`, plus: `groupSafe = groupBlockReasons(filter).length === 0`; `groupBlockReasons: allowFilteredGroup && reasons.length>0 ? [] : reasons` (override hides blockers in preview but `groupSafe` stays as computed).

### 2.8 `group-plan.ts`

- `GROUP_BATCH_HARD_MIN = 1`, `GROUP_BATCH_HARD_MAX = 100`.
- `emptyPlan(): []`.
- `planBatches(count, batchSize): number[]` — `total = max(0, floor(count))`; `size = min(100, max(1, floor(batchSize) || 1))` (so `0`, `NaN`, negatives → `1`, `1000` → `100`); then greedy fill: `while (remaining > 0) { next = min(size, remaining); push(next); remaining -= next }`. Examples: `(40,15) → [15,15,10]`; `(7,15) → [7]`; `(38,15) → [15,15,8]`; `(0,15)`/`(-3,15) → []`; `(10,0) → ten 1s`; `(10,1000) → [10]`; `(250,100) → [100,100,50]`; `(501,1000)` max batch `100`.
- `resolveGroupBatchSize(value): number` — `Number(value)`; not finite (`undefined`, `'oops'`) → **15** (default); else `min(100, max(1, floor(parsed)))` (`0→1`, `5000→100`, `15.9→15`). Note asymmetry with `planBatches`: bad input here defaults to 15, there to 1.

### 2.9 `message-hash.ts`

- `normalizeMessageBody(body)` = `body.trim().replace(/\s+/g, ' ').toLowerCase()` (`'  Hello   አለም  '` → `'hello አለም'`).
- `messageBodyHash(body)` — FNV-1a 32-bit over normalized string: `hash = 0x811c9dc5`; per char (UTF-16 code units): `hash ^= charCodeAt(i); hash = Math.imul(hash, 0x01000193)`; return `(hash >>> 0).toString(16).padStart(8,'0')` → always `^[0-9a-f]{8}$`. Uniqueness enforced per language by `@@unique([normalizedHash, language])`.
- `titleFromBody(body)` — first line that is non-empty after `trim()` (`split('\n').map(trim).find(Boolean) ?? ''`); if `length <= 40` return it; else `firstLine.slice(0, 39).trimEnd() + '…'` → total length exactly **40**, ends with `…`. Empty body → `''`.

## 3. Validation

**Template editor (`templateSaveSchema`)** — `id: max 64 ('tooLong')`; `key: enum TEMPLATE_KEYS ('invalidTemplate')`; `language: enum ['en','am'] ('invalidLanguage')`; `body: trim, min 1 ('required'), max 1000 ('tooLong')`; `active: checkbox` (accepts `true|'true'` etc.). Unknown `{var}` flagging: editor computes `unknownVariables(body)` live and shows `t('unknownVariables') + ': ' + unknown.join(', ')`; `validateTemplateBody` gates save. The safety note `t('templateSafetyNote')` is always rendered above the editor (README 37: “no diagnoses/treatments/specialties/prices”). Amharic drafts flagged “needs native review” (UI/i18n: `amDraft`).

**Message body (custom composer / draft / library / campaign `customBody`)** — `validateMessageBody` (§2.2): required → too-long (1000) → unknown-variable, in that precedence; sensitive terms (`SENSITIVE_TERMS`) are **warnings only** (UI key `messages.sensitiveContentWarning`), never `ok:false`.

**Unknown `{var}` rules** — only `{name}`-shaped tokens are variables; `{}` empty-name counts as unknown (regex captures `''`, not in allowlist → unknown list contains `''`); stray braces are literal; unknown names render as `''` even if validation is bypassed.

**Forbidden-word rules (two layers)**
1. Runtime warning layer: `SENSITIVE_TERMS` (19 terms, §2.2) — diagnoses/treatments/specialties/prices, EN + Amharic (`ምርመራ`, `ህክምና`, `መድኃኒት`, `ቀዶ`, `ዋጋ`, `ብር`).
2. Seed-time hard gate (`seed-safety.test.ts`): every seeded template + library body (en & am) must not contain any `REAL_SERVICES` name (`nameEn`/`nameAm`, case-insensitive substring) nor any of `BANNED_PHRASES = ['diabetes', 'blood pressure', 'የስኳር', 'ደም ግፊት']`.

**Filter / campaign / misc schemas (zod, i18n message codes)** — date pattern `/^\d{4}-\d{2}-\d{2}/` (`'invalidDate'`), `''` allowed for date fields. Tags: ≤20 items (`'tooMany'`), each ≤40 (`'tooLong'`). `serviceIds`: ≤50, each ≤64. `handPickedIds`: ≤1000, each ≤64. `birthdayMonth`: int 0–12 (coerced). `taskSendSchema.id` 1–64; `taskSkipSchema.reason` trim 1–300 (`MAX_SKIP_REASON`); `taskEditSchema.message` trim 1–1000; `campaignFormSchema`: `title?` ≤120, `customBody?` ≤1000, `libraryItemId?` ≤64, `languageMode ∈ PATIENT|FIXED`, `fixedLanguage ∈ en|am`, `deliveryMode ∈ INDIVIDUAL|GROUP`, `expiresAt` date-or-empty, `acknowledgedGroupWarning` checkbox. `messageDraftSchema`/`libraryItemSchema`: `body` trim 1–1000, `language`, (`category` for library, ≤120 title). `audienceFormSchema` = recipient filter + `name` trim 1–120.

## 4. Role rules

- `MESSAGE_ROLES = ['OWNER','RECEPTION']` — view outbox/drafts/library/compose and send (prepare) messages. `MESSAGE_OWNER_ROLES = ['OWNER']` — template editing, campaign creation (unless enabled), reports, jobs.
- Predicates: `canEditTemplates(role)` → `role === 'OWNER'`; `canManageLibrary` → OWNER (archive/edit/hide; *reuse* is everyone with access); `canManageAudiences` → OWNER (saved `Audience` rows); `canOverrideGroupFilter` → OWNER (the `group_sms_allow_for_filtered` setting); `canSendMessages` → OWNER ∥ RECEPTION; `canSendCampaigns(role, receptionAllowed)` → `OWNER` always, otherwise `role === 'RECEPTION' && receptionAllowed` (setting `reception_can_send_campaigns`, default `false`) — STAFF never.
- STAFF has no messages access at all (403 on `/messages*`). Group-override is OWNER-only; others see the restriction and must choose a different audience or individual delivery. Drafts are private to `createdBy`. (Manual §1 table.)

## 5. Business rules & invariants (numbered, exact)

1. **Manual-link SMS, individual**: `buildSmsLink(phoneE164, body, platform)` = `` `sms:${phone}${platform==='ios' ? '&' : '?'}body=${encodeURIComponent(body)}` `` — Android **and** `other` use `?body=`, iPhone uses `&body=` (README 36: verify on real Android `sms:+251…?body=…` and iPhone `sms:+251…&body=…`). Body is percent-encoded (Amharic + newlines → `%0A`); throws `'Cannot build an SMS link with an empty body'` on blank body. Nothing is ever sent by the app; the operator taps send in the phone app.
2. **Group link**: `buildGroupSmsLink(phones, body, platform)` — trims, drops blank entries, joins with `,`: `sms:+251911111111,+251922222222?body=…` (same `?`/`&` rule); throws with no recipients or empty body. `detectPlatform(ua)`: `/iphone|ipad|ipod/` → `'ios'`, `/android/` → `'android'` (on lowercased UA), else `'other'`. `getSmsProvider(mode)`: `'GATEWAY'` → gateway (unimplemented, bulk-capable), anything else/blank → manual `MANUAL_LINK` provider (`supportsBulkIndividual: false`).
3. **Never a real group message for individual campaigns**: `Campaign.deliveryMode = INDIVIDUAL` creates **one `MessageTask` per eligible patient**; group text is only the `GROUP` mode’s per-batch shared body (rendered from the first recipient — prefer generic wording, manual §10). `GROUP` is unavailable for automatic task types (reminders/follow-ups/recalls/birthdays) — campaign/wizard creation only; pre-6B campaigns never go group. Group mode requires a prior audience preview + the acknowledge checkbox (everyone in the group sees every number).
4. **Consent / do-not-message**: only gate is `patient.doNotMessage` (+ active + phone) — see §2.5. **Consent columns no longer exist**: migration `20261016000000_remove_sms_consent` dropped `consentSms/consentDate/consentRecordedBy`; README 35/38’s “no consent” wording is stale. `messages.blockedNoConsent` remains in catalogs as a legacy key but `BLOCKED_REASON_LABEL_KEYS` never emits it. Opt-out (“stop messages”) sets `doNotMessage` and cancels all open tasks (`cancelPendingForPatient`); re-enabling is OWNER-only; both audited; the send flow then re-checks and refuses (README 39).
5. **Blocked reason keys** (stored raw in `MessageTask.blockedReason`, rendered via `BLOCKED_REASON_LABEL_KEYS`): `inactive`, `doNotMessage`, `invalidPhone`, `sourceCancelled` → `messages.blocked*`. Precedence fixed (§2.5). Checked at creation *and* at open; failures never delete tasks.
6. **Language selection**: `Campaign.languageMode 'PATIENT'` → `resolveLanguage(patient.preferredLanguage)` per recipient; `'FIXED'` → `fixedLanguage` (en|am). Body lookup falls back requested → `en` → `''` (`bodyFor`). Template rows are `active = true` only. `MessageTask.language` records the rendered language.
7. **Idempotent task generation**: DB unique tuple `@@unique([sourceType, sourceId, templateKey, patientId])`; `createTask` returns `'created'` or `'exists'` (catches Prisma `P2002`). `sourceId` conventions: appointment reminder/same-day → `` `${appointment.id}#${startsAt.getTime()}` `` (versioned per start time); confirmation / thank-you / no-show → `appointment.id`; schedule-change button → `` `${appointment.id}#sc#${Date.now()}` `` (always a new row); follow-up → `followUp.id`; birthday → `` `${patient.id}#${clinicDateKey(now)}` ``; recall → `` `${patient.id}#${recallPeriodKey(now, tz)}` `` (`YYYY-MM` clinic-local). Reschedule cancels the old pair (`APPT_REMINDER`+`APPT_SAME_DAY` for that appointment) and creates the versioned new one. `runMessageJobs` is safe to run twice (README 40).
8. **Expiry / cancellation**: open statuses = `['PENDING','OPENED']`. `expireTasks(now)`: any open task with `expiresAt != null && expiresAt < now` → `EXPIRED`. Cancellations → `CANCELLED`: appointment rescheduled/completed/no-show/cancelled (scoped `cancelPendingScope`), follow-up no longer `OPEN`, patient opt-out (all open). Window rules per schedule: confirmation `sendBy = endOfDay`, reminder `sendBy = startsAt − 60 min` and `expiresAt = startsAt`, same-day expires at start, no-show +3 d, follow-up +7 d 23:59, recall +30 d, birthday next local midnight; thank-you/immediate never expire.
9. **Campaign cap from settings**: `campaign_max_recipients`, default `300`, zod bounds 1–300. `preview.overCap = eligible.length > cap` — operator must narrow the filter or raise the cap (manual §3). Cap compares **eligible**, not matched.
10. **Group batching**: `group_sms_max_recipients` default `15`, zod bounds 1–100 (also enforced by `resolveGroupBatchSize`). Split fills earlier batches first (40@15 → 15/15/10). Batch rows: `CampaignBatch @@unique([campaignId, batchNumber])`, `batchNumber` 1-based; “Yes, sent” → batch `SENT` + its tasks `SENT`; “Skip” (reason required ≤300) → `SKIPPED`; “Cancel remaining” cancels pending recipients **and** pending batches. Batches are re-planable (never hard-bound before sending).
11. **Group-safety**: group delivery allowed iff `groupBlockReasons` is empty (no criteria, or plain visited-between incl. presets `last30/90/180` but **not** `thisYear`) or the OWNER override `group_sms_allow_for_filtered` (default `false`) is on. Blocker order `service, tag, category, upcoming, purchase, filtered`. Override hides the blocker list in preview; acknowledge checkbox + fresh preview still required.
12. **Render invariants**: lines containing `{review_link}` are dropped when the link value is empty; unknown/missing variables render as `''`; group body = first recipient’s render (manual §10).
13. **SMS counting invariant**: one counter for both editor and previews — GSM-7 160 single/153 multipart, Unicode 70/67, extension chars cost 2, any non-GSM char switches the whole message to Unicode; Amharic ⇒ Unicode.
14. **Library duplicate guard**: `MessageLibraryItem @@unique([normalizedHash, language])` — same wording twice in one language rejected (`messages.libraryDuplicate`); en vs am copies of the same text are distinct items (`pairId` links them). Soft delete (`active=false`) only; auto-title `titleFromBody`; favorites + `useCount`/`lastUsedAt` order the list.
15. **Recall generation window** (from `generate.ts`): skip patients with any future `SCHEDULED/CONFIRMED` appointment; last activity = newest of last `COMPLETED` appointment / last `CONFIRMED` sale; require `lastActivity ≤ now − recall_months`; patients pre-filtered `active && !doNotMessage`.
16. **Sizes**: message/task/library/draft body 1000; template body 1000; skip reason 300; campaign/audience title 120; task id ≤64.

## 6. i18n keys used (complete list)

Namespace: `messages.` (components call `useTranslations('messages')`; bare keys below). Group-block reasons are composed as `messages.groupBlock.<suffix>`; blocked reasons as `messages.blocked*`.

- Templates (13): `tplApptReminder`, `tplApptConfirmation`, `tplApptSameDay`, `tplNoShow`, `tplScheduleChange`, `tplFollowupDue`, `tplRecall`, `tplGlassesReady`, `tplThankYou`, `tplBirthday`, `tplWelcome`, `tplClosureNotice`, `tplCustom`.
- Task statuses (7): `statusPending`, `statusOpened`, `statusSent`, `statusSkipped`, `statusExpired`, `statusCancelled`, `statusBlocked`.
- Sources (6): `sourceAppointment`, `sourceFollowUp`, `sourceBirthday`, `sourceRecall`, `sourceCampaign`, `sourceManual`.
- Campaign statuses (4): `campaignDraft`, `campaignActive`, `campaignCompleted`, `campaignCancelled`.
- Delivery modes (2): `deliveryIndividual`, `deliveryGroup`.
- Batch statuses (3): `batchPending`, `batchSent`, `batchSkipped`.
- Library categories (5): `libReminder`, `libGreeting`, `libAnnouncement`, `libFollowup`, `libOther`.
- Group-block (6 suffixes → `groupBlock*`): `groupBlockService`, `groupBlockTag`, `groupBlockCategory`, `groupBlockUpcoming`, `groupBlockPurchase`, `groupBlockFiltered`.
- Blocked reasons (4): `blockedInactive`, `blockedDoNotMessage`, `blockedInvalidPhone`, `blockedSourceCancelled`. Legacy/unused: `blockedNoConsent`.
- Body validation errors: `messageRequired`, `messageTooLong`, `unknownVariable` (first error only), plus editor display key `unknownVariables`.
- Safety: `templateSafetyNote`; warning surface: `sensitiveContentWarning`.
- Zod message codes (resolved by the form layer): `required`, `tooLong`, `tooMany`, `invalidDate`, `invalidTemplate`, `invalidLanguage`.
- (Manual/UI-adjacent keys emitted alongside these rules: `overCap`, `campaignOverCap`, `groupSafe`, `groupBlocked`, `groupNotAllowed`, `groupAckText`, `groupWarningRequired`, `libraryDuplicate`, `amDraft` — listed for completeness, implemented in components.)

## 7. Test inventory

Framework: Vitest. Assertion strings are exact; port 1:1 to PHPUnit.

**`templates-validation.test.ts`** (6)
- `validateMessageBody > rejects empty bodies`: `'   '` → `ok false`, `error 'messageRequired'`.
- `> rejects bodies over the campaign length cap`: `'x'.repeat(1001)` → `ok false`, `error 'messageTooLong'`.
- `> rejects unknown variables but is fine with known ones`: `'Hello {name}, see you on {date}'` → ok; `'Hello {does_not_exist}'` → `ok false`, `error 'unknownVariable'`, `unknown` contains `'does_not_exist'`.
- `> passes clean bodies and reports sensitive terms as warnings`: `'Dear {name}, come for your appointment.'` → `ok true`, `sensitive === []`; `'Dear {name}, your cataract surgery costs 5000 birr.'` → `ok true`, `sensitive` contains `'cataract'` and `'surgery'`.
- `scanSensitiveContent > matches case-insensitively (Latin and Ge'ez)`: `'You have GLAUCOMA'` contains `'glaucoma'`; `'ዋጋው ብር ነው'` ⊇ `['ዋጋ','ብር']`.
- `> returns empty for clean text`: `'Please come for a check-up.'` → `[]`.

**`render.test.ts`** (8; mocks Prisma `messageTemplate.findMany`)
- `loadTemplateBodies > builds a key:language -> body map for active templates only`: called with `{ where:{active:true}, select:{key:true,language:true,body:true} }`; `map.get('APPOINTMENT_CONFIRMATION:en') === 'Hi {name}'`, `:am === 'ሰላም {name}'`.
- `bodyFor > prefers the requested language`: `('…:am' map, key, 'am') → 'am-body'`.
- `> falls back to English`: language `'fr'` → `'en-body'`.
- `> returns the empty string when nothing matches`: empty map → `''`.
- `renderVariables > renders static variables and date/time when startsAt is present`: ctx `startsAt 2026-10-12T09:00:00.000Z`, tz `Africa/Addis_Ababa` → `name 'Abebe'`, `clinic 'Vision Eye'`, `review_link` set, `date` defined, `time '12:00'`.
- `> adds doctor and reopen_date only when provided`: `doctor 'Dr B'`, `reopen_date '2026-11-01'`, `date` undefined, `time` undefined.
- `renderMessage > substitutes template variables in the body`: `'Dear {name} at {clinic}'` → `'Dear Abebe at Vision Eye Clinic'`.
- `resolveLanguage > accepts supported languages and falls back to English otherwise`: `'am'→'am'`, `'en'→'en'`, `null→'en'`, `undefined→'en'`, `'fr'→'en'`.

**`schedule.test.ts`** (17; TZ `Africa/Addis_Ababa`, `S = DEFAULT_MESSAGING_SETTINGS`)
- confirmation: `dueAt === now (2026-10-10T07:30Z)`, `expiresAt null`, `sendBy === '2026-10-10T21:00:00.000Z'` (next local midnight).
- reminder: `startsAt 2026-10-13T09:00Z` → returns `null` when `now === startsAt`.
- reminder normal: `now 2026-10-09` → `dueAt '2026-10-12T11:00:00.000Z'` (14:00 local day before), `sendBy '2026-10-13T08:00:00.000Z'` (startsAt−60 min), `expiresAt === startsAt`.
- reminder late booking: `createdAt 2026-10-12T12:00Z`, `now 2026-10-12T15:00Z` → `dueAt === now`.
- same-day (before 08:00): `now 2026-10-12T21:00Z` → `dueAt '2026-10-13T05:00:00.000Z'`, `sendBy === startsAt`, `expiresAt === startsAt`.
- same-day (window passed before appt day): `now 2026-10-12T20:00Z` → `dueAt === now`, `sendBy === startsAt`.
- same-day (08:00 passed): `now 2026-10-13T06:30Z` → `dueAt === now`.
- same-day: `now === startsAt` → `null`.
- no-show: `now 2026-10-10T15:00Z` → `dueAt '2026-10-11T06:00:00.000Z'`, `sendBy null`, `expiresAt '2026-10-14T06:00:00.000Z'` (72 h).
- follow-up lead: due `2026-10-20`, `now 2026-10-01` → `dueAt '2026-10-17T06:00:00.000Z'`, `sendBy null`, `expiresAt '2026-10-27T20:59:00.000Z'`.
- follow-up overdue: `now 2026-10-18T00:00Z` → `dueAt === now`.
- thank-you: appt `2026-10-10T09:00Z` → `dueAt '2026-10-11T06:00:00.000Z'`, `sendBy null`, `expiresAt null`.
- birthday: `now 2026-10-10T20:00Z` → `dueAt '2026-10-10T05:00:00.000Z'`, `expiresAt '2026-10-10T21:00:00.000Z'`.
- recall: `dueAt === now (2026-10-10T12:00Z)`, `expiresAt '2026-11-09T12:00:00.000Z'`.
- immediate: `=== { dueAt: now, sendBy: null, expiresAt: null }`.
- recallPeriodKey: `(2026-10-10T22:00Z) → '2026-10'`; `(2026-10-31T20:00Z) → '2026-10'`; `(2026-10-31T21:00Z) → '2026-11'`.
- laterOf: `b,b,a` all return `b`; ties → first arg.

**`group-plan.test.ts`** (10)
- `planBatches`: `(40,15) → [15,15,10]`; `(7,15) → [7]`; `(0,15)`/`(-3,15) → []`; `(38,15) → [15,15,8]`; clamps `(10,0)` length 10, `(10,1000) → [10]`, `(250,100)` length 3; `(501,1000)` → `Math.max(batches) === GROUP_BATCH_HARD_MAX`.
- `resolveGroupBatchSize`: `undefined → 15`, `'oops' → 15`, `0 → 1`, `5000 → 100`, `15.9 → 15`.
- `emptyPlan: → []`.

**`message-hash.test.ts`** (8)
- `normalizeMessageBody`: `'  Hello   አለም  ' → 'hello አለም'`; `'' → ''`.
- `messageBodyHash`: stable across calls; `'Hello   World' === 'hello world'`; `'x'` matches `/^[0-9a-f]{8}$/`; `'Message A' ≠ 'Message B'`.
- `titleFromBody`: `'Appointment reminder\nnext line' → 'Appointment reminder'`; `'x'.repeat(100)` → length 40 and ends with `'…'`; `'' → ''`.

**`recipient-filter.test.ts`** (25)
- `hasCriteria / isPlainVisitedBetween`: empty filter → `hasCriteria false`; `lastVisitFrom+To` only → `hasCriteria true`, `isPlainVisitedBetween true`; any of tags/`serviceIds`/`serviceCategory('surgery')`/`upcoming('HAS')`/`boughtServiceFrom`/`language('en')` (+ a visit range) → plain false; `handPickedIds ['p1']` + visit range → plain false.
- `groupBlockReasons / groupAllowed`: tags → `['tag']`; serviceIds → `['service']`; serviceCategory → `['category']`; `upcoming 'NONE'` → `['upcoming']`; `boughtServiceTo` → `['purchase']`; `language 'en'` only → `['filtered']`; `lastVisitFrom` only → `[]`; `groupAllowed(tags, false) === false`, `(tags, true) === true`, `(visit-range, false) === true`.
- `matchesRecipientFilter`: tag exact match/mismatch; language `'am'` vs filter `'am'` true / `'en'` false; serviceIds `['s1','s2']` vs `['s2']` true, `['s9']` false; tags `['a','b']` ALL `['a','b']` true, ALL `['a','z']` false, ANY `['a','z']` true; `lastPurchaseAt 2026-08-01` within `2026-07-01..2026-09-01` true, null purchase false; hand-picked `['p1']` true even with empty criteria, empty filter false; upcoming `HAS`+`hasUpcoming` true, `NONE`+`hasUpcoming` false.
- `resolvePresetRange`: no preset returns explicit `{ '2026-01-01', '' }`; `last90` on `2026-10-10` → `{ '2026-07-12', '2026-10-10' }`; `thisYear` → `{ '2026-01-01', '2026-10-10' }`.
- `normalizeRecipientFilter`: `null` and `'nope'` → `emptyRecipientFilter()`; stored JSON `{tags:[' VIP ','x'], tagMode:'ALL', serviceIds:['s1'], language:'en', upcoming:'HAS', presetRange:'last90', junk:true}` → `tagMode 'ALL'`, `language 'en'`, `upcoming 'HAS'`, `presetRange 'last90'`, `birthdayMonth 0`, `handPickedIds []`.
- `normalizeLegacyFilter`: legacy `{tags:['VIP'], tagMode:'ANY', language:'am', lastVisitFrom:'2026-01-01', …}` → copies `tags`, `language 'am'`, `lastVisitFrom '2026-01-01'`.
- `summarizeRecipients`: (a) 3 candidates, empty filter, cap 300 → `eligible []`, `preview.eligible 0` (empty criteria matches nobody, incl. the invalid-phone candidate); (b) tags `['VIP']`, 4 candidates (1 doNotMessage, 1 wrong tag) → `eligible ['p1','p4']`, `matched 3`, `eligible 2`, `excluded.doNotMessage 1`, `overCap false`; (c) 2 matches, cap 1, override false → `overCap true`, `groupBlockReasons ['tag']`; override true → `groupBlockReasons []`.

**`index.test.ts`** (20; covers templates/provider/eligibility/schedule/campaign-filter/roles)
- `template rendering`: `renderTemplate('Hi {name} at {clinic}', …) → 'Hi Almaz at VEC'`; `renderTemplate('Hi {nope}', {name:'Almaz'}) → 'Hi '`; 3-line body with empty `review_link` → `'Thanks for visiting.\nSee you soon.'` (line dropped), with link → output contains `'https://x.y'`; `unknownVariables('Hello {name} {bogus}') → ['bogus']`; `validateTemplateBody('Hello {name}').ok === true`; `('Hello {bogus}').ok === false`.
- `SMS character counting`: `'hello'` → `GSM-7`; `'a'.repeat(161)` → 2 parts; 160 → 1 part; `'{'` → `length 2`; `'ሰላም ለአንተ'` → `Unicode`, 1 part; `'ሰ'.repeat(71)` → 2 parts; `previewTemplate('Hi {name}, see you {date} at {time}.').text` contains `'Hemnet Tesfaye'`, `unknown []`.
- `sms: link builder`: `buildSmsLink('+251911000000','hello','android') === 'sms:+251911000000?body=hello'`; `'ios'` → `'sms:+251911000000&body=hello'`; Android link of `'ሰላም\nbye'` contains `'%0A'`, no raw `'\n'`, contains `encodeURIComponent('ሰላም')`; platform detection iPhone→`'ios'`, Android→`'android'`, `null→'other'`; `getSmsProvider('nonsense').mode === 'MANUAL_LINK'`, `('GATEWAY').mode === 'GATEWAY'`.
- `eligibility`: each reason in turn — `active:false → 'inactive'`, `doNotMessage:true → 'doNotMessage'`, `phoneE164 '12345' → 'invalidPhone'`, `sourceStatus:'CANCELLED' → 'sourceCancelled'`; clean candidate → `isEligible true`.
- `appointment scheduling (Africa/Addis_Ababa)` (uses `combineDateAndTime` fixtures): reminder due `addis('2026-10-11','14:00')`, expires at `startsAt`; late booking → `dueAt === createdAt`; `now === startsAt` → `null`; same-day due `addis('2026-10-12','08:00')`, no-show due `addis('2026-10-13','09:00')`; thank-you due `addis('2026-10-13','09:00')`; follow-up (due 10-20, now 10-09) due `addis('2026-10-17','09:00')`; birthday due `addis('2026-10-12','08:00')`, expires `combineDateAndTime('2026-10-13','00:00', tz)`.
- `campaign filters`: candidate `{tags:['glasses','vip'], lastVisitAt addis('2026-01-01','09:00'), categories:['glasses']}` — `tags ['vip'] ANY` true; `['vip','surgery'] ALL` false; `serviceCategory 'glasses'` true / `'surgery'` false; visit range `2025-01-01..2025-12-31` false; empty candidate + `upcoming 'HAS'` false; `birthDate 1990-10-12` + `birthdayMonth 10` true. `exclusionsFor`: doNotMessage → contains `'doNotMessage'`, `'bad'` phone → `'invalidPhone'`, inactive → `'inactive'`, clean → `[]`. `summarizeCandidates([a, b(doNotMessage), c], emptyFilter, cap 1)` → `eligible ['a','c']`, `excluded.doNotMessage 1`, `overCap true`.
- `campaign role rules`: `canSendMessages('RECEPTION') true`, `('STAFF') false`; `canSendCampaigns('OWNER', false) true`, `('RECEPTION', false) false`, `('RECEPTION', true) true`.

**`seed-safety.test.ts`** (2; guards `prisma/seed-messages.TEMPLATES` en+am and `prisma/seed-library.LIBRARY_PAIRS` bodyEn/bodyAm)
- `never mentions a service name (English or Amharic)`: for every body × every `REAL_SERVICES` `nameEn`/`nameAm` → lowercase substring assertion fails if found.
- `never mentions diabetes or high blood pressure (English or Amharic)`: against `BANNED_PHRASES = ['diabetes','blood pressure','የስኳር','ደም ግፊት']`.

**`provider.test.ts`** (11; adjacent `provider.ts`, needed for §5)
- `buildSmsLink`: android → `'sms:+251911111111?body=Hello'`; `'ios'` → `'sms:+251911111111&body=Hi'`; Amharic `'መልካም ልደት'` → exact `encodeURIComponent` form, no spaces; `'   '` throws.
- `buildGroupSmsLink`: two phones → `'sms:+251911111111,+251922222222?body=Move%20to%203rd%20floor'`; ios → starts `'sms:+1,+2&body=hi'`; drops blank/whitespace entries; throws on `[]`, `['  ']`, and empty body.
- `detectPlatform`: iPhone→`'ios'`, Android→`'android'`, `''`/`null`→`'other'`.
- `providers`: `new ManualLinkProvider().supportsBulkIndividual === false`; `getSmsProvider('MANUAL_LINK').mode 'MANUAL_LINK'`, `(undefined) → 'MANUAL_LINK'`, `('GATEWAY').mode 'GATEWAY'` and `.supportsBulkIndividual === true`.

## 8. Data contracts

**`MessageTask`** (one prepared message for one patient)
`id cuid` · `patientId` (FK Patient) · `templateKey MessageTemplateKey` · `sourceType MessageSourceType` · `sourceId String` (never null: record id **or** stable period key, §5.7) · `campaignId String?` (FK Campaign) · `language String` (`en`|`am` as rendered) · `renderedMessage String` (final text; one-off edit allowed) · `dueAt timestamptz` · `sendBy timestamptz?` · `expiresAt timestamptz?` · `status MessageStatus @default(PENDING)` · `blockedReason String?` (one of the 4 keys, only when BLOCKED) · `skipReason String?` (≤300, only when SKIPPED) · `handledBy String?`, `handledAt timestamptz?` · `editedBy String?`, `editedAt timestamptz?` · `campaignBatchId String?` (FK CampaignBatch, `onDelete: SetNull`) · `createdAt`.
Constraints: `@@unique([sourceType, sourceId, templateKey, patientId])`; indexes `[status,dueAt]`, `[patientId,createdAt]`, `[campaignId]`, `[status,expiresAt]`, `[campaignBatchId]`.

Enums: `MessageTemplateKey = APPT_REMINDER|APPT_CONFIRMATION|APPT_SAME_DAY|NO_SHOW|SCHEDULE_CHANGE|FOLLOWUP_DUE|RECALL|GLASSES_READY|THANK_YOU|BIRTHDAY|WELCOME|CLOSURE_NOTICE|CUSTOM`; `MessageSourceType = APPOINTMENT|FOLLOWUP|BIRTHDAY|RECALL|CAMPAIGN|MANUAL`; `MessageStatus = PENDING|OPENED|SENT|SKIPPED|EXPIRED|CANCELLED|BLOCKED`.

**Status lifecycle** (open set = `PENDING`,`OPENED`):
- create → `PENDING`, or `BLOCKED` immediately if `blockedReason(...) != null` (reason stored at creation).
- open (re-check) → `OPENED` (clears `blockedReason`) or `BLOCKED` + reason; refused for terminal `SENT|SKIPPED|CANCELLED` (`taskAlreadyHandled`).
- “Not yet” → back to `PENDING` (refused for `SENT|CANCELLED`).
- confirm “Yes, sent” → `SENT` + `handledBy/handledAt`, `blockedReason: null`; idempotent if already `SENT`; audited `MESSAGE_SENT`.
- skip (reason required) → `SKIPPED` + `skipReason/handledBy/handledAt`; refused for `SENT`; audited `MESSAGE_SKIPPED`.
- job expiry → `EXPIRED` (open + `expiresAt < now`); cancellation paths → `CANCELLED` (§5.8). `BLOCKED → OPENED` possible when eligibility is restored on re-check.

**`MessageTemplate`**: `key + language` `@@unique`, `body`, `active @default(true)`, `updatedBy?`. Loader sees `active=true` only.

**`Campaign`**: `title` (≤120), `templateKey?` XOR `customBody?`, `languageMode String` (`PATIENT`|`FIXED`), `fixedLanguage?`, `filterJson Json` (RecipientFilterValues, never trusted blindly), `status CampaignStatus @default(DRAFT)` = `DRAFT|ACTIVE|COMPLETED|CANCELLED`, `deliveryMode CampaignDeliveryMode @default(INDIVIDUAL)` = `INDIVIDUAL|GROUP`, `libraryItemId?` (FK MessageLibraryItem, SetNull), `expiresAt?`, `createdBy`, timestamps. Relations: `tasks[]`, `batches[]`. Doc invariant: every recipient gets their own task — never one group message at the campaign level.

**`CampaignBatch`**: `campaignId`, `batchNumber Int` (1-based, `@@unique([campaignId,batchNumber])`), `size Int`, `status CampaignBatchStatus @default(PENDING)` = `PENDING|SENT|SKIPPED`, `handledBy?`, `handledAt?`, `skipReason?`, timestamps.

**`MessageDraft`**: `createdBy` (private per user), `title?` (≤120), `body` (1–1000), `language @default("en")`, timestamps, `@@index([createdBy, updatedAt])`.

**`MessageLibraryItem`**: `title` (auto ≤40 from `titleFromBody`, editable), `body`, `language`, `category MessageLibraryCategory @default(REMINDER)` = `REMINDER|GREETING|ANNOUNCEMENT|FOLLOWUP|OTHER`, `normalizedHash`, `isFavorite @default(false)`, `useCount @default(0)`, `lastUsedAt?`, `createdBy`, `active @default(true)` (soft delete), `pairId?` (en↔am link), `@@unique([normalizedHash, language])`, indexes on `[language,category]`, `[isFavorite,active]`, `[useCount]`, `[lastUsedAt]`, `[pairId]`.

**`Audience`**: `name` (1–120), `filterJson Json`, `createdBy`, timestamps, `@@index([createdBy])`. Membership never frozen — the filter re-evaluates at use time; OWNER-managed.

Settings consumed (defaults from seed): `reception_can_send_campaigns=false`, `campaign_max_recipients=300` (bounds 1–300), `group_sms_allow_for_filtered=false`, `group_sms_max_recipients=15` (bounds 1–100), `sms_mode='MANUAL_LINK'`, `reminder_send_time='14:00'`, `reminder_send_by_offset_minutes=60`, `followup_lead_days=3`, `recall_months=12`, `timezone='Africa/Addis_Ababa'`.
