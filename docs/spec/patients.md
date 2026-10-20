# Patients — Laravel rebuild spec

Source of truth: working tree of `1st 20 service providers/erp` @ `8a1e734`.
Files read: `src/modules/patients/**` (incl. `sick-leave/`, all `*.test.ts`, `components/*.tsx`),
`src/app/(app)/patients/**`, `src/app/api/patients/**`, `prisma/schema.prisma`
(`Patient`, `Tag`, `PatientTag`, `PatientSickLeave`), README patient lines, `src/lib/{phone,csv,audit,action,errors,export,roles,require-role,datetime,image}.ts`, `messages/{en,am}.json`.

## 1. Routes & pages (legacy URL → purpose; HTTP method for actions)

Pages (all server-rendered, all gated by `requirePageRole(PATIENT_ROLES)`):

| Legacy URL | Purpose |
|---|---|
| `GET /patients` | List + filter + sort + paginate + export links. Query: `q,idNo,dateFrom,dateTo,status,sort,dir,page`. |
| `GET /patients/new` | Create form (`PatientForm mode="create"`). |
| `GET /patients/[id]` | Detail. Query: `tab` (`overview｜sales｜appointments｜messages｜sick`, default `overview`); sick tab also reads `edit=<sickLeaveId>` (inline edit form). Writes `VIEW` audit. |
| `GET /patients/[id]/edit` | Edit form (`PatientForm mode="edit"`); 404 (`notFound()`) when patient missing. |
| `GET /patients/import` | CSV import wizard (`ImportForm`). |

Server actions (Next actions are HTTP `POST` with the page URL + action id; Laravel: one POST route each, same names):

| Action (exported from `src/modules/patients/index.ts`) | Purpose | Result payload |
|---|---|---|
| `POST createPatientAction(prev, FormData)` | Create patient | `{id}` or `{needsConfirm:true, similar:[{id,fullName}]}` |
| `POST updatePatientAction(prev, FormData)` | Update patient | same as create |
| `POST setPatientTagsAction` | Replace patient's tag set | ok/fail |
| `POST setPatientActiveAction` | Toggle active flag | ok/fail |
| `POST setDoNotMessageAction` | Stop / resume messaging | ok/fail |
| `POST importPatientsAction` | 2-step CSV import (`step=preview` → `step=import`) | `ImportPreview` \| `ImportDone{imported,skipped}` |
| `POST capturePatientIdPhotoAction` | Capture/upload ID photo on detail page | `{id}` |
| `POST createPatientSickLeaveAction` | New sick-leave record | `{id}` |
| `POST updatePatientSickLeaveAction` | Edit sick-leave record | `{id}` |
| `POST deletePatientSickLeaveAction` | Hard-delete sick-leave record | ok/fail |

API routes:

| Route | Purpose |
|---|---|
| `GET /api/patients/export?format=csv\|xlsx&q&idNo&dateFrom&dateTo&status&sort&dir` | Filtered CSV/XLSX download (same filters as list, no `page`). Audits `EXPORTED`. |
| `GET /api/patients/template` | CSV template download (`patients-template.csv`, `text/csv; charset=utf-8`, `attachment`, `Cache-Control: no-store`). |

Adjacent (patient data, not under `api/patients/`): `GET /api/search?q=` — patient type-ahead (`SEARCH_ROLES = ['OWNER','RECEPTION']`); `q` trimmed, capped to `MAX_QUERY`, empty result if `q.length < 2`, patient branch only when `q.length >= 3`, `active: true`, OR(name contains insensitive, `phoneSearchVariants` contains), `orderBy fullName asc`, `take 6`.

Auth behavior (all pages/actions/API): no session → redirect to secret login URL; `mustChangePassword` → pages redirect `/change-password`, API `302 /change-password`, actions `fail('passwordChangeRequired')`; wrong role → page redirect `/403`, API `403 Forbidden`, action `{ok:false,error:'forbidden'}`.

Nav: PMS group item `{ href: '/patients', roles: ['OWNER','RECEPTION'] }` (`src/modules/nav/index.ts`).

## 2. Pure logic to port

### `duplicates.ts`
- `normalizeNameForKey(name: string): string` — `name.normalize('NFKC').toLowerCase().replace(/[^\p{L}]/gu,'')` (keeps letters of any script, drops spaces/punctuation/digits).
- `levenshtein(a: string, b: string): number` — classic two-row DP; equal → 0; empty side → other length.
- `isSimilarName(a, b): boolean` — normalize both; if either empty → `false`; equal → `true`; `longest = max(len)`; `longest < 5` → `false`; threshold = `longest >= 10 ? 2 : 1`; return `levenshtein(na, nb) <= threshold`.

### `consent.ts` — REMOVED from working tree (deleted in commit `c757d67`; columns dropped by migration `20261016000000_remove_sms_consent`). Kept here because README §16/§18 and `patients.import.columnsHint` still reference consent. Spec from git history:
- `type ConsentFields = { consentSms: boolean; consentDate: Date|null; consentRecordedBy: string|null }`.
- `initialConsent(wantsConsent, now, userId)` → `{true, now, userId}` when granted, else all-false/null.
- `nextConsent(before, wantsConsent, now, userId)` → withdrawn → cleared; already granted → `before` untouched (no re-record); first grant → `{true, now, userId}`.
- `consentChanged(before, after)` → any of the three fields differs (dates by ms instant).
- `consentAuditEntry(before, after)` → `{old,new}` when changed, else `null`; used for dedicated audit action `PATIENT_CONSENT_CHANGED`.
- **Current rebuild: no consent fields exist on `Patient`, no consent column in import, no consent UI/filter.**

### `tags.ts`
- `tagColorFor(name: string): string` — `hash = 0; for c in name: hash = (hash*31 + c.charCodeAt(0)) | 0`; palette index `Math.abs(hash) % 8` over `['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#06B6D4','#EC4899','#84CC16']`. Name is already lowercased before call → color is stable per tag.

### `import.ts`
Constants: `IMPORT_COLUMNS = ['fullName','phone','sex','birthDate','tags','preferredLanguage']`; `IMPORT_REQUIRED_COLUMNS = ['fullName','phone']`; `MAX_IMPORT_ROWS = 1000`; `MAX_IMPORT_BYTES = 2*1024*1024`; `MAX_TAG_LENGTH = 30`; `MAX_TAGS_PER_PATIENT = 10`.
- `IMPORT_ERROR_MESSAGES` (English, for the downloadable error CSV only): `required:'Required'`, `tooLong:'Too long'`, `invalid:'Invalid value'`, `invalidPhone:'Invalid phone number'`, `phoneTaken:'Phone number already exists'`, `invalidSex:'Invalid sex — use Male/Female/Other (or M/F/O)'`, `invalidDate:'Invalid date — use YYYY-MM-DD and a date in the past'`, `invalidLanguage:'Invalid language — use en or am'`, `fileTooLarge:'File is larger than 2 MB'`, `tooManyRows:'More than 1000 rows'`, `missingColumns:'Missing required column'`.
- Cell parsers: `parseSexCell` — trim/lower; `''`→null; aliases `m|male→MALE`, `f|female→FEMALE`, `o|other→OTHER`; else invalid. `parseBirthDateCell` — `''`→null; must match `^\d{4}-\d{2}-\d{2}$`; `Date.parse(v+'T00:00:00Z')` not NaN; string `<= todayIso()` (UTC YYYY-MM-DD); else invalid. `parseTagsCell` — split on `/[;,|]/`, trim, lowercase, drop empties; any part `>30` → invalid; `>10` parts → invalid; result deduped via `Set`. `parseLanguageCell` — trim/lower; `''`→fallback; `en|am` ok; else invalid.
- `validateImportRow(raw, ctx): ImportRow` — `fullName` trimmed, empty → `{fullName,required}`, `>120` → `{fullName,tooLong}`; phone via `parsePhone` (see §3) → on error push `{phone, error}` (`required|invalidPhone`), else if in `ctx.existingPhones` **or** `ctx.seenPhones` → `{phone,phoneTaken}` else add to `seen`; sex/birthDate/tags/language push `{sex,invalidSex}` / `{birthDate,invalidDate}` / `{tags,invalid}` / `{preferredLanguage,invalidLanguage}`. `values` set **only** when `errors.length===0` (and phoneE164 non-null); fallbacks: sex null, birthDate null, tags `[]`, language `ctx.defaultLanguage`. Returns `rowNumber: 0` (caller rewrites).
- `parseImport(text, ctx): ImportResult` — `parseCsv` (RFC-4180: strips UTF-8 BOM, quoted cells, `""` escapes, CR/LF/CRLF, drops rows that are all-blank). Empty file → `missingColumns = ['fullName','phone']`, no rows. Header row (index 0) cells trimmed; missing required columns → return those, no rows. Data rows = `parsed.slice(1)`; `tooManyRows = dataRows.length > 1000` → return flag only, no rows. Builds `raw` per header column (`cells[i] ?? ''`, trimmed), one shared `seenPhones` set across rows (mutated), `rowNumber = index + 2` (header is row 1). `validCount = rows with values`, `errorCount = rows.length - validCount`.
- Import insert semantics (in `actions.ts`): per valid row `patient.create` with `notesNonClinical: null, active: true, createdBy: actor`; tags upserted (`tagColorFor`) then `patientTag.createMany(skipDuplicates)`; `P2002` race → row counted as skipped (no error); each created row audits `PATIENT_CREATED`. `skipped = result.rows.length - imported` (invalid rows + races).

### `roles.ts`
- `PATIENT_ROLES: Role[] = ['OWNER','RECEPTION']` — "Patients are visible to owners and reception — never to clinical staff."

### Other pure helpers in module
- `queries.ts`: `sortFrom(v)` → whitelist `['fullName','birthDate','createdAt']` else `'fullName'`; `dirFrom(v)` → `'desc'` only if exactly `'desc'`, else `'asc'`; `buildPatientWhere(filters)` (see §7); `findSimilarPatients({fullName,birthDate,excludeId?,limit?})` → **no birthDate → `[]`**; loads up to **100** candidates with exact `birthDate` equal (`YYYY-MM-DDT00:00:00Z`, minus `id != excludeId`), filters `isSimilarName`, returns first **`limit ?? 5`**. Warning-only, never blocks.
- `tabs.ts`: `tabFrom(v)` → whitelist `['overview','sales','appointments','messages','sick']` else `'overview'`; `loadPatientTab(tab,id)` → `{ready:true, count}` with counts from sales/appointments/messaging/sick-leave modules (overview count 0).
- `actions.ts` helpers: `toDate(v)` = `new Date(v+'T00:00:00Z')`; `defaultLanguage()` = settings `default_language === 'am' ? 'am' : 'en'`; `snapshot(patient)` = the 10-field object in §5; `idPhotoKeyFromUrl(url)` = `null` unless URL starts `/api/uploads/`, then `decodeURIComponent` of the rest (invalid escapes → null); `storeIdPhoto(file)` = ext from MIME map (`image/png→png, image/jpeg→jpg, image/webp→webp`, else `ValidationError {idPhoto:'idPhotoInvalidType'}`), key `patient-id-{randomUUID}.{ext}`, `storage.put`, URL `/api/uploads/{key}`; `isPrismaError(err,'P2002')` → phone-uniqueness race path.
- CSV: `toCsv(headers,rows)` → UTF-8 BOM + CRLF + RFC-4180 quoting; `parseCsv` as above.
- Phone: `normalizePhone`/`parsePhone`/`phoneSearchVariants`/`formatPhoneDisplay` — see §3/§7.

## 3. Validation schema

`patientFormSchema` (zod; messages are **i18n keys** resolved client-side as `validation.<key>`; first issue per field wins — `validate()` → `ValidationError{fields}` → `fail('validationError', fields)`):

| Field | Rules / transform | key |
|---|---|---|
| `fullName` | trim, min 1, max 120 | `required` / `tooLong` |
| `phone` | trim, min 1; `normalizePhone(value) !== null`; transform → E.164 | `required` / `invalidPhone` |
| `sex` | `'' \| MALE \| FEMALE \| OTHER` → `''` becomes `null` | `invalidSex` |
| `birthDate` | trim; `''` ok, else `^\d{4}-\d{2}-\d{2}$` + `Date.parse(v+'T00:00:00Z')` valid + `v <= todayIso()`; `''` → `null` | `invalidDate` |
| `preferredLanguage` | `'' \| en \| am`; `''` → `null` (filled from `default_language` setting at save) | `invalidLanguage` |
| `notesNonClinical` | trim, max **2000**; `''` → `null` | `tooLong` |
| `idNo` | trim, max **32**, optional; `''` stored as `null` | `tooLong` |
| `idPhoto` | optional File; MIME in `{image/png,image/jpeg,image/webp}`; size ≤ **1 048 576 B (1 MiB)** | `invalid` / `idPhotoInvalidType` / `idPhotoTooLarge` |

Phone normalization (rule 8, `src/lib/phone.ts`): strip `space - . ( )`; accept `09XXXXXXXX|07XXXXXXXX` → `+2519…/+2517…`, `9XXXXXXXX|7XXXXXXXX` → `+251…`, `+2519…|+2517…` kept; anything else → `invalidPhone`; empty/whitespace → `required` (`parsePhone`).

`patientSickLeaveSchema` (cross-field `.refine`):

| Field | Rules | key |
|---|---|---|
| `patientId` | min 1, max 64 | `required` / `tooLong` |
| `startDate` | trim, `^\d{4}-\d{2}-\d{2}$` | `invalidDate` |
| `endDate` | trim, same regex; **cross-field: `endDate >= startDate`** (path `endDate`) | `invalidDate` / `endBeforeStart` |
| `reason` | trim, max **200**, optional; `''` → null | `tooLong` |
| `note` | trim, max **2000**, optional; `''` → null | `tooLong` |

Ad-hoc (non-zod) action validations:
- `setPatientTagsAction`: split `tags` CSV on `,`, trim, lowercase, drop empties, dedupe (`Set`); any name length > **30** → `fail('validationError',{tags:'tooLong'})`; count > **10** → `fail('validationError',{tags:'tooMany'})`.
- `setDoNotMessageAction`: `reason = reason.trim().slice(0,300)`; `stop==='true' && !reason` → `fail('validationError',{reason:'required'})`; `stop!=='true' && actor.role !== 'OWNER'` → `fail('forbidden')` (OWNER-only re-enable).
- Missing patient/record → `fail('unknownError')` (except sick-leave create: `fail('patientRequired')`; sick-leave update with foreign patient: `fail('forbidden')`).
- Import action: no/empty file → `fail('noFile')`; file > 2 MiB (or echoed CSV > 2 MiB) → `fail('fileTooLarge')`; confirm step without `confirm='true'` → `fail('confirmationRequired')`; `missingColumns` → `fail('missingColumns')`; `tooManyRows` → `fail('tooManyRows')`; preview with 0 data rows → `fail('emptyFile')`.
- Capture photo: no/empty file → `fail('noFile')`.
- Global envelopes: `forbidden`, `passwordChangeRequired`, `validationError`, `unknownError` (see `withAuth`, `src/lib/action.ts`).

## 4. Role matrix

`PATIENT_ROLES = ['OWNER','RECEPTION']`; STAFF is never in it. "OWNER only" appears in exactly one place in this module (marked ★).

| Surface | OWNER | RECEPTION | STAFF |
|---|---|---|---|
| Pages `/patients`, `/patients/new`, `/patients/[id]`, `/patients/[id]/edit`, `/patients/import` | ✅ | ✅ | ❌ redirect `/403` |
| `createPatientAction` | ✅ | ✅ | ❌ `forbidden` |
| `updatePatientAction` | ✅ | ✅ | ❌ `forbidden` |
| `setPatientTagsAction` | ✅ | ✅ | ❌ `forbidden` |
| `setPatientActiveAction` | ✅ | ✅ | ❌ `forbidden` |
| `setDoNotMessageAction` (stop = true) | ✅ | ✅ | ❌ `forbidden` |
| `setDoNotMessageAction` (resume = false) | ✅ | ❌★ **OWNER only** (`fail('forbidden')`) | ❌ `forbidden` |
| `importPatientsAction` | ✅ | ✅ | ❌ `forbidden` |
| `capturePatientIdPhotoAction` | ✅ | ✅ | ❌ `forbidden` |
| `createPatientSickLeaveAction` | ✅ | ✅ | ❌ `forbidden` |
| `updatePatientSickLeaveAction` | ✅ | ✅ | ❌ `forbidden` |
| `deletePatientSickLeaveAction` | ✅ | ✅ | ❌ `forbidden` |
| `GET /api/patients/export` | ✅ (audited) | ✅ (audited) | ❌ `403` |
| `GET /api/patients/template` | ✅ | ✅ | ❌ `403` |
| `GET /api/search` (patient branch) | ✅ | ✅ | ❌ `403` |
| Nav item "Patients" (PMS group) | shown | shown | hidden |

Forced password change: pages → `/change-password`, API → redirect there, actions → `passwordChangeRequired`.

## 5. Audit actions

`writeAudit` (`src/lib/audit.ts`): **diffs** `old`/`new` (only changed fields stored; no change → both JSON null), redacts secrets (`passwordHash`, `password`, `token`… → `[REDACTED]`), captures client IP (`x-forwarded-for` first hop else `x-real-ip`), **never throws** (failure logged, business op proceeds).

`snapshot()` (patient old/new payload): `{fullName, phoneE164, sex, birthDate, preferredLanguage, notesNonClinical, doNotMessage, doNotMessageReason, idPhotoUrl, idNo, active}` — excludes tags, `createdBy`, timestamps.

| Action string | Entity / entityId | `old` | `new` | Notes |
|---|---|---|---|---|
| `PATIENT_CREATED` | `Patient` / id | — | snapshot | also per imported row |
| `PATIENT_UPDATED` | `Patient` / id | snapshot(before) | snapshot(after) | |
| `PATIENT_TAGS_CHANGED` | `Patient` / id | `{tags:[...before].sort()}` | `{tags:[...names].sort()}` | only when set actually changed (length or membership) |
| `PATIENT_STATUS_CHANGED` | `Patient` / id | `{active}` | `{active}` | skipped when value unchanged |
| `PATIENT_DNM_CHANGED` | `Patient` / id | `{doNotMessage, reason}` | `{doNotMessage, reason}` (`reason` = new reason or `null`) | skipped when state+reason unchanged |
| `PATIENT_ID_PHOTO_CHANGED` | `Patient` / id | `{idPhotoUrl}` (old) | `{idPhotoUrl}` (new) | capture action only |
| `PATIENT_SICK_LEAVE_CREATED` | `PatientSickLeave` / id | — | `{patientId, startDate, endDate, reason, note}` | |
| `PATIENT_SICK_LEAVE_UPDATED` | `PatientSickLeave` / id | `{startDate, endDate, reason, note}` | same shape | |
| `PATIENT_SICK_LEAVE_DELETED` | `PatientSickLeave` / id | `{patientId, startDate, endDate, reason, note}` | — | only hard delete in module |
| `VIEW` | `Patient` / patientId | — | — | detail page open |
| `EXPORTED` | `patient` (lowercase!) / `export` | — | `{...filters, format}` | via `auditExport` from export route |

`VIEW` logging rule (detail page): one row per **user + patient per 60 s** (`VIEW_DEDUPE_MS = 60_000`). Implementation: React `cache()` per request + `pg_advisory_xact_lock(hashtext('view:{patientId}:{userId}'))` inside a transaction, then `findFirst` for a `VIEW` by that user on that patient with `createdAt >= now-60s`; insert only when absent. Failures are swallowed (`console.error`) — audit never blocks the page. Body runs twice per request (HTML + RSC pass); lock serializes the two writers.

## 6. Business rules & invariants

1. Phone stored **E.164** `+2519XXXXXXXX` / `+2517XXXXXXXX`, unique (`@unique` + `P2002` → `phoneTaken`). Display format `09…` via `formatPhoneDisplay`; `tel:` links use raw E.164.
2. Duplicate phone **blocks** create/update (unless it's the same record): `fail('phoneTaken', undefined, {id,fullName})` → UI links to existing patient.
3. Similar name + same birth date is a **non-blocking confirmation**: on submit without `confirm=true`, if any `findSimilarPatients` hit → `ok({needsConfirm, similar})`; UI shows amber panel + hidden `confirm=true`; second submit skips the similarity check (phone check still runs). No birthDate → never warns. Candidate scan caps: 100 loaded / 5 returned.
4. Similarity thresholds: normalized-equal always similar; names of normalized length < 5 never similar unless equal; length 5–9 → Levenshtein ≤ 1; length ≥ 10 → ≤ 2.
5. No hard delete of patients — only `active` toggle ("deactivate/reactivate", confirm dialogs). Only `PatientSickLeave` rows are hard-deleted (with confirm).
6. New patient defaults: `active: true`, `createdBy: actor.id`, `preferredLanguage` from `''`→settings `default_language` (`am` iff `am`, else `en`), `idNo: ''→null`, `notesNonClinical: ''→null`, `birthDate` as UTC midnight `@db.Date`.
7. Edit keeps prior `preferredLanguage` when field left `''`; keeps prior `idPhotoUrl` when no new file; old photo blob removed from storage (best-effort) when replaced.
8. **No clinical notes**: `notesNonClinical` (≤2000) and sick-leave `reason`/`note` are non-clinical only — schema comment + i18n hint "Never record diagnoses or treatment details here."
9. **ID photo off by default**: `idPhotoUrl` null unless explicitly uploaded/captured; MIME allow-list png/jpeg/webp; server cap **1 MiB** (i18n text says "2 MB" — drift, code wins); client pre-resizes to max 800 px / JPEG q0.7 before upload; served only to signed-in users from `/api/uploads/`.
10. Tags: lowercased, trimmed, comma-split, deduped; ≤ **30** chars each, ≤ **10** per patient; tags are created on the fly (upsert by name) and **never deleted** (only `PatientTag` links replaced wholesale in one transaction); deterministic color per name (§2).
11. **Stop messages** (`doNotMessage`): stopping requires a non-empty reason (trimmed, silently truncated to **300**), cancels all open message tasks (`cancelPendingForPatient` → `status CANCELLED`), audits; **re-enabling is OWNER only** and clears the reason to `null`.
12. Status/DNM toggles are idempotent no-ops (no audit) when nothing changes.
13. **Sick leave off by default** (nothing pre-created): patient tab shows an empty state + "Add first record"; records: start/end `@db.Date`, `endDate >= startDate`, reason ≤200, note ≤2000, `recordedById` = actor; list sorted `startDate desc, createdAt desc`.
14. **Import 1000-row cap**: `>1000` data rows → reject entire file (`tooManyRows`); file > **2 MiB** → `fileTooLarge` (both steps; confirm step re-checks echoed text length).
15. Import is preview→confirm: preview echoes the raw CSV text; confirm re-parses that text with **fresh** DB phones and requires `confirm=true`; only rows with `errors === []` are inserted; **never partial per row**, invalid rows stay in the error report; `skipped = totalRows - imported`.
16. Import duplicates: DB phone or earlier-in-file phone → `phoneTaken` on the **later** row (first occurrence wins); insert race (`P2002`) → silently skipped.
17. Import-created patients: `notesNonClinical: null`, `active: true`, `createdBy: actor`; per-row `PATIENT_CREATED` audits; language from column or settings default; **no consent fields exist** (README/i18n consent references are stale).
18. Import error report: client-generated CSV, headers `row,field,issue,value`, filename `patient-import-errors.csv`, one line per (row, error), English messages from `IMPORT_ERROR_MESSAGES`.
19. Row numbering: spreadsheet row numbers, header = 1, first data row = 2.
20. Birth dates must be `YYYY-MM-DD`, parseable, and **not in the future** (string compare against today's UTC ISO date) — in both form and import.
21. Dates stored as `@db.Date` (UTC midnight, no time); displayed via clinic timezone (`formatInTz` / `formatDateRange`, `dateStyle:'medium'`, `en-GB`).
22. List page size **20**; export cap **5000** rows (`EXPORT_ROW_CAP`, no pagination in export).
23. `VIEW` audit dedupe: 1 row per user per patient per **60 s** (§5).
24. Every mutation + export writes an audit row; audit failure never fails the request.
25. Deactivated patients: excluded from `/api/search` patient branch (`active: true`), still visible in list with `status=false` filter and in exports.
26. Sick-leave update validates record belongs to the submitted `patientId` else `forbidden`.

## 7. Queries: list filters (search by name/phone fragment incl. phoneSearchVariants), sort whitelist, pagination cap, default sort, export CSV columns/filename

`buildPatientWhere(filters)` (shared by list, export route, export link):
- `q` (trimmed; page/export cap **100 chars**) → `OR [` `fullName contains q, mode insensitive` **,** for each `phoneSearchVariants(q)`: `phoneE164 contains variant` **,** `idNo contains q, mode insensitive` `]`.
- `phoneSearchVariants(fragment)`: digits only (`\D` stripped); none → `null` (clause skipped); set always contains raw digits; if starts `09|07` add `+251…` (drop leading 0) and national w/o 0; if starts `2519|2517` add `+…`. Example `0911` → `['0911','+251911','911']` (set order).
- `idNo` (trimmed, cap **32** on page/export) → `idNo equals, mode insensitive`.
- `dateFrom` (trimmed, `^\d{4}-\d{2}-\d{2}$` else dropped) → `createdAt >= startOfDay` (clinic TZ); `dateTo` (same) → `createdAt < endOfDay` (exclusive local midnight of next day).
- `status`: `'true'` → `active:true`; `'false'` → `active:false`; else no clause.
- Combine as `{AND:[…]}` or `{}`.

Pagination/sort: `PATIENTS_PAGE_SIZE = 20`; `total = count(where)`; `pageCount = max(1, ceil(total/20))`; `page = clamp(Number(sp.page)||1, 1, pageCount)`; `skip=(page-1)*20`, `take=20`.
Sort whitelist `PATIENT_SORTS = ['fullName','birthDate','createdAt']` (**default `fullName`**), dir whitelist `asc` (**default**) / `desc`; `orderBy: [{sort:dir},{id:'asc'}]` (stable tiebreak).
List select: `id, fullName, phoneE164, sex, birthDate, preferredLanguage, doNotMessage, active, createdAt, idNo`, `tags` (join tag, `orderBy tag.name asc`).
URL builder omits defaults (`sort=fullName`, `dir=asc`, `page=1`).

Export (`GET /api/patients/export`): caps `q≤100`, `idNo≤32`, dates sliced to 10 and regex-checked, `status` only `true|false|''`; `take: 5000`.
Filename: `patients-YYYY-MM-DD.csv` (xlsx → same name with `.xlsx`; xlsx MIME `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, CSV `text/csv; charset=utf-8`; both `attachment` + `Cache-Control: no-store`).
Columns → cells (exact order): `id` → id; `idNo` → `idNo ?? ''`; `fullName`; `phone` → `phoneE164`; `sex` → `sex ?? ''`; `birthDate` → `YYYY-MM-DD` or `''`; `preferredLanguage`; `tags` → `'; '`-joined tag names; `doNotMessage` → bool; `active` → bool; `createdAt` → full ISO string. Audit: `EXPORTED` (entity `patient`).

Sick-leave queries: `listPatientSickLeaves(patientId)` → rows + `recordedByName`, `orderBy [{startDate:'desc'},{createdAt:'desc'}]`; `getPatientSickLeave(id)`; `countPatientSickLeaves(patientId)` (tab badge).

Detail query `getPatient(id)`: patient + tags (name asc) + `createdByName` resolved from `User` (no FK, `createdBy` raw id).

## 8. i18n keys used (complete list of translation keys referenced)

`patients.*`: `title, newPatient, editPatient, importCsv, importTitle, searchPlaceholder, dateFrom, dateTo, statusFilter, fullName, phone, phoneHint, sex, sexMale, sexFemale, sexOther, sexUnknown, birthDate, languageDefault, langEn, langAm, notes, notesHint, idPhoto, idPhotoUpload, idPhotoCapture, idPhotoView, idPhotoHint, idNo, idNoHint, create, update, similarTitle, similarBody, similarConfirmHint, openExisting, tapToCall, tagsTitle, tagsHint, tagsCreateHint, noTags, saveTags, tabOverview, tabSales, tabAppointments, tabMessages, tabSick, createdMeta, updatedMeta, deactivate, reactivate, deactivateConfirm, reactivateConfirm, activeHint, inactiveHint, backToList, hasErrors, createdCol, dnmYes, dnmActive, dnmInactive, messagingTitle, stopMessages, stopConfirm, stopReasonLabel, resumeMessages, sickLeaveTitle, sickLeaveNew, sickLeaveEdit, sickStartDate, sickEndDate, sickReason, sickNote, sickDeleteConfirm, sickEmpty, sickAddFirst, sickRecordedBy`.

`patients.import.*`: `chooseFile, csvHint, columnsHint, exampleHint, validate, downloadTemplate, previewTitle, summary, rowCol, issuesCol, confirmImport, confirmHint, errorReport, done, importAnother`, plus dynamic `errors.{noFile|fileTooLarge|tooManyRows|missingColumns|emptyFile|confirmationRequired}`.

`common.*`: `search, all, active, inactive, filter, clear, empty, noResults, showing, previous, next, status, language, edit, actions, delete, save, saving, cancel, close, loading, phoneTaken, noFile, unknownError, forbidden, validationError, passwordChangeRequired` (last four rendered via `ActionError` / `tc(state.error)`; `ActionError.KNOWN_KEYS` also lists `fileTooLarge, emptyFile, confirmationRequired, missingColumns, tooManyRows, cannotDeactivateSelf, saved` — but `ImportForm.LOCAL_ERROR_KEYS` intercepts the six import errors first).

`validation.*` (field errors): `required, tooLong, invalidPhone, invalidSex, invalidDate, invalidLanguage, tooMany, invalid, endBeforeStart`. **Drift:** schema emits `idPhotoInvalidType`, `idPhotoTooLarge` which exist only under `common.*`, not `validation.*` (legacy bug — pick one namespace in Laravel).

`export.*`: `formatCsv, formatXlsx` (ExportLinks CSV/Excel buttons).

Present in `messages/{en,am}.json` but **unused** by current patients code (stale): `patients.consentFilter, consentLabel, consentHint, consentTitle, consentGranted, consentNone, consentMeta, consentYes, consentNo, dnmNo, tabNotReady, tabEmpty, exportCsv, sickNew, sickEdit`; `import.columnsHint` still says optional column `consent`; `idPhotoHint` says "max 2 MB" while code caps 1 MiB. `am.json` mirrors `en.json` key-for-key in `patients`.

## 9. Test inventory

### `src/modules/patients/duplicates.test.ts` (vitest)
- **normalizeNameForKey › lowercases and strips punctuation and spaces**: `'  Mekdes,  Alemu. '` → `'mekdesalemu'`.
- **keeps non-latin letters (Amharic)**: `'ሰላም ገብረ'` → `'ሰላምገብረ'`.
- **treats identical spellings as equal keys**: `normalizeNameForKey('Mekdes Alemu') === normalizeNameForKey('MEKDES ALEMU')`.
- **levenshtein › is 0 for equal strings**: `('abebe','abebe') → 0`.
- **counts single edits**: `('marta','martha') → 1`; `('mekdes','mekides') → 1`.
- **is symmetric**: `levenshtein('girma','german') === levenshtein('german','girma')`.
- **isSimilarName › flags a one-letter spelling difference**: `('Mekdes Alemu','Mekides Alemu') → true`.
- **ignores case, punctuation and extra spaces**: `('Mekdes Alemu','  mekdes, alemu!') → true`.
- **does not flag completely different names**: `('Mekdes Alemu','Bekele Wolde') → false`; `('Almaz Tesfaye','Solomon Tadesse') → false`.
- **does not flag very short names that differ**: `('Sara','Mary') → false`.
- **allows a small difference in long names**: `('Woinishet Getu','Woinshet Getu') → true`; `('Woinishet Getu','Woinishet Girmay') → false`.

### `src/modules/patients/import.test.ts`
Fixtures: `ctx({defaultLanguage:'en', existingPhones:new Set()})`; `csv(...rows)` joins with `\n`; `HEADER = 'fullName,phone,sex,birthDate,tags,preferredLanguage'`.
- **structure › reports missing required columns**: input `'fullName,sex\nAlmaz,FEMALE'` → `missingColumns === ['phone']`, `rows` length 0.
- **returns no rows for a header-only file**: `missingColumns []`, `rows` 0, `validCount 0`.
- **flags files above the row cap**: `MAX_IMPORT_ROWS+1` data rows → `tooManyRows true`, `rows` 0.
- **handles BOM, quoted cells and CRLF line endings**: BOM + `"Almaz, Tesfaye",0911223344,FEMALE,1985-03-12,"glasses; follow-up",en` CRLF → 1 row; `values.fullName === 'Almaz, Tesfaye'`; `values.tags === ['glasses','follow-up']`.
- **phone normalization** (`it.each`, each asserts `errors === []` and `values.phoneE164`): `0911223344→+251911223344`, `911223344→+251911223344`, `+251911223344→+251911223344`, `09 11 22 33 44→+251911223344`, `0911-223-344→+251911223344`, `0711223344→+251711223344`, `+251711223344→+251711223344`.
- **rejects invalid phones with a per-row error**: rows `Almaz,12345` / `Bekele,09112` → `validCount 0`, `errorCount 2`, each `errors` contains `{field:'phone',key:'invalidPhone'}`.
- **duplicates › marks a phone that already exists in the database**: `existingPhones={+251911223344}`, row `Almaz,0911223344` → contains `{field:'phone',key:'phoneTaken'}`, `values === null`.
- **marks a phone repeated inside the same file (second row only)**: `Almaz,0911223344` + `Duplicate,911223344` → row 0 `errors []`, row 1 contains phoneTaken; `validCount 1`, `errorCount 1`.
- **field validation › requires a name**: `,0911223344` → contains `{field:'fullName',key:'required'}`.
- **rejects bad sex, dates and language**: row `Almaz,0911223344,X,1985-03-12,,fr` → error keys contain `invalidSex` and `invalidLanguage`; exact `{field:'preferredLanguage',key:'invalidLanguage'}` present.
- **rejects future and malformed birth dates**: row A (empty dates) → 0 errors; row B (tomorrow `YYYY-MM-DD`) → contains `{field:'birthDate',key:'invalidDate'}`; row C `12/31/1990` → same.
- **applies the default language when the column is empty**: `ctx({defaultLanguage:'am'})`, row `Almaz,0911223344` → `values.preferredLanguage === 'am'`.
- **validates only the failing rows and keeps the good ones importable**: 4 rows → `rowNumbers [2,3,4,5]`, `validCount 2`, `errorCount 2`; `values` non-null for rows 0 and 3, null for rows 1 (missing name) and 2 (`not-a-phone`).

### `src/modules/patients/roles.test.ts`
- **allows OWNER and RECEPTION**: `hasRole('OWNER', PATIENT_ROLES) === true`; `hasRole('RECEPTION', PATIENT_ROLES) === true`.
- **denies STAFF**: `hasRole('STAFF', PATIENT_ROLES) === false`; `PATIENT_ROLES` not to contain `'STAFF'`.

### `consent.test.ts` (deleted with `consent.ts`; re-implement only if consent is revived)
- `initialConsent` true → `{consentSms:true, consentDate:NOW, consentRecordedBy:'user-1'}`; false → all-false/null.
- `nextConsent` keeps original date/user when already consented; records `now+actor` on first grant; clears on withdraw.
- `consentChanged/consentAuditEntry`: identical snapshots → `false`/`null`; un-ticked → pair with `{old:before,new:after}`; first grant → `{old:none,new:after}`.

E2E (Playwright, for parity): `e2e/roles.spec.ts › staff cannot open patient records` — login as STAFF → `/patients` shows forbidden page. README §19 also pins direct `GET /api/patients/export` → `403`.

## 10. UI behavior per page

### `/patients` (list)
- Header: `title` + buttons `importCsv` → `/patients/import`, `newPatient` → `/patients/new`.
- Filter card (GET form, 4-col grid): `q` (search input, `searchPlaceholder`, cap 100), `idNo` (search, `idNoHint`, cap 32), `dateFrom`, `dateTo` (date inputs, regex-validated), `status` select (`all`/`active`/`inactive`), buttons `filter`, `clear` (link `/patients`), plus `ExportLinks` (CSV + Excel buttons → `/api/patients/export?…&format=csv|xlsx`, `download` attr).
- Table (8 cols): sortable `fullName`, `birthDate`, `createdAt` headers (toggle asc↔desc, `↑/↓` arrow, resets `page=1`); static `idNo, phone, sex, tagsTitle, status`. Rows: name link → `/patients/{id}` (+ red pill `dnmYes` when `doNotMessage`), `idNo ?? '—'`, `tel:{phoneE164}` link showing E.164, sex label or `—`, birthDate `YYYY-MM-DD` or `—`, tag pills (color = `tag.color`), status pill (`Active`/`Inactive`), `createdAt` date.
- Empty state: `common.noResults` when any of q/dateFrom/dateTo/status set, else `common.empty`; colSpan 8.
- Footer: `common.showing {from}–{to} of {total}` (`from=(page-1)*20+1`, `to=min(page*20,total)`, `0–0 of 0` when empty); `previous`/`next` links (disabled `<span>` with `opacity-50` at edges).

### `/patients/new` & `/patients/[id]/edit`
`PatientForm` card (`max-w-2xl`): fields `fullName` (required text), `phone` (`type=tel`, placeholder `0912345678`, hint `phoneHint`, prefilled `formatPhoneDisplay`), `sex` select (`sexUnknown/sexMale/sexFemale/sexOther`), `birthDate` (`type=date`, `max=today`), `preferredLanguage` select (`languageDefault/langEn/langAm`), `notesNonClinical` textarea + amber `notesHint`, `idPhoto` file (`accept image/png,image/jpeg,image/webp`) with `Upload` (removes `capture` attr) / `Capture` (`capture="environment"`) buttons, `View` button + full-screen lightbox dialog (backdrop close, `aria-modal`) when a photo exists, thumbnail 64×64 when present, hint `idPhotoHint`; `idNo` text `maxLength=32` placeholder `idNoHint`.
Behavior: submit intercepts and client-resizes photo (`resizeImageFile`, 800 px / JPEG 0.7); per-field errors render under the field from `validation.*`; on success `router.push('/patients/{id}')` + refresh; buttons `create`/`update` (pending label `saving`) + `cancel` link to `route`.
Duplicate phone → red alert box: `common.phoneTaken` + link `openExisting {name}` → `/patients/{id}` (replaces generic `ActionError`).
Similar-name round 1 → amber box: `similarTitle`, `similarBody`, list of links to similar patients, `similarConfirmHint` ("Save again to add this patient anyway."), hidden `confirm=true` re-submitted on round 2.
Edit mode adds hidden `id`; create does not.

### `/patients/[id]` (detail)
- Header: `fullName`, status pill (`active`/`inactive`), red `dnmYes` pill when opted out; buttons `backToList` → `/patients`, `edit` → `/patients/[id]/edit`.
- Main col: phone card — `tel:` link with `formatPhoneDisplay(phoneE164)` + `tapToCall`; `dl` of sex / birthDate / idNo / language (`langAm` if `am` else `langEn`); meta lines `createdMeta {name,date}` (creator name or `—`, clinic TZ) and `updatedMeta {date}`.
- `PatientIdPhotoCapture` card: hidden `patientId`, file input with `capture="environment"` + `Upload`/`Capture`/`View` buttons, live object-URL preview (64×64 or dashed placeholder), `idPhotoHint`, submit `save` (pending `saving`); errors render as `common.<error>` text; success → `router.refresh()`; lightbox dialog as in form.
- Tab nav (`?tab=` links, `prefetch=false`): `tabOverview, tabSales, tabAppointments, tabMessages, tabSick`; badge `{count}` shown only on the **active non-overview** tab (`loadPatientTab` counts; all loaders `ready:true` — `tabNotReady`/`tabEmpty` are dead keys).
- Tab bodies: Overview → notes card (`notes` heading, `notesNonClinical || '—'`, amber `notesHint`); Sales → `PatientSalesList`; Appointments → `PatientAppointmentsList`; Sick → `PatientSickLeaveTab`; Messages → `PatientMessagesList`.
- Sidebar: `TagsEditor` (current tag pills or `noTags`; text input with `datalist` of all tag names, placeholder `tagsHint`, hint `tagsCreateHint`; hidden comma-joined `tags`; Save disabled until value differs (dirty) or pending; field error `validation.tooLong/tooMany`; success → refresh).
- `StopMessagesCard` (remounted via `key='stopped'|'allowed'`): heading `messagingTitle`; state line `dnmActive` (+`(reason)`) or `dnmInactive`; when allowed: danger button `stopMessages` → reveals required textarea `stopReasonLabel` + `stopConfirm` (danger submit) / `cancel`; when stopped: `resumeMessages` submit (hidden `stop=false`; OWNER-only server-side, non-OWNER sees generic `forbidden` via `ActionError`); success → refresh.
- `PatientStatusButton`: hidden `id` + `active` = inverse of current; `window.confirm(deactivateConfirm|reactivateConfirm)` before submit; hint `activeHint`/`inactiveHint`; danger button `deactivate`/`reactivate`; success → refresh.
- Side effect: page logs `VIEW` audit (§5).

### `/patients/import`
- `ImportTitle` heading + `ImportForm` card: file input (`accept .csv,text/csv`, `required` until preview), selected filename or `csvHint`, `columnsHint`, `exampleHint`; buttons `validate` (submit `step=preview`, pending `loading`) and `downloadTemplate` (link `/api/patients/template`).
- Local errors (rendered from `patients.import.errors.*`): `noFile, fileTooLarge, tooManyRows, missingColumns, emptyFile, confirmationRequired`; any other error goes through `ActionError`.
- Preview state: hidden `csv` (raw text echo) + hidden `confirm=true`; summary box `previewTitle` + `summary {valid} valid rows, {errors} rows with errors`; scrollable table (cols `rowCol, fullName, phone, status, issuesCol`) — row number, raw fullName/phone, status = `common.active` (emerald) when `errors==[]` else `patients.hasErrors` (red), issues joined `'; '` from `IMPORT_ERROR_MESSAGES`.
- Actions row: primary submit `step=import` label `confirmImport {count}` — disabled while pending **or** `validCount===0`; `errorReport` button (only when `errorCount>0`) downloads `patient-import-errors.csv` (headers `row,field,issue,value`); hint `confirmHint`.
- Done state: emerald line `done {imported} {skipped}`; buttons → `/patients` (`title`) and `importAnother` (clears filename + `window.location.reload()`).

### Sick-leave tab (`?tab=sick`)
- Heading `sickLeaveTitle`; `PatientSickLeaveForm`: hidden `patientId` (+ hidden `id` when editing); heading `sickLeaveNew`/`sickLeaveEdit`; `startDate`/`endDate` date inputs (`max=today`, default today / prefilled from `?edit=`), `reason` text `maxLength=200`, `note` textarea `maxLength=2000`; per-field `validation.*` errors; submit `save` (pending `saving`) + `cancel` (navigates to `route`); on success → refresh + `onSuccess`.
- `PatientSickLeaveList`: empty → card `sickEmpty` + link `sickAddFirst` (`{route}?tab=sick`); else table with headers `sickStartDate, sickEndDate, sickReason, sickRecordedBy, actions` — **legacy quirk:** first cell renders `formatDateRange(start,end)` (spanning both date headers), then reason, then note (2-line clamp w/ title attr, `—` when null), recorded-by name (`—`), actions: `edit` link `{route}?tab=sick&edit={id}` and `delete` button → `confirm(sickDeleteConfirm)` → action → `window.location.reload()`; deleting row is opacity-dimmed while pending.
- Edit selection: `?edit=<id>` matches a row → form prefilled with `startDate/endDate` sliced to `YYYY-MM-DD`.

No print button on patients pages (only CSV/Excel export links); export/template downloads carry `attachment` + `no-store`.
