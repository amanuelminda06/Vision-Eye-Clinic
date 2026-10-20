# Services & Sales — Laravel rebuild spec

Source: `1st 20 service providers/erp` (legacy Next.js 16 / Prisma / TS). Files read: `src/modules/services/**`,
`src/modules/sales/**`, `src/lib/money.ts` + `money.test.ts`, `prisma/schema.prisma` (Service, ServicePriceHistory,
Sale, SaleLine, ReceiptCounter), README §"Money / time / phone conventions" + checklist 20–24, `messages/en.json`,
route files under `src/app`, `src/app/api/sales/export/route.ts`. Money is `NUMERIC(12,2)` everywhere; times are
UTC `timestamptz` displayed in the clinic timezone (settings, default `Africa/Addis_Ababa`); phones are E.164
`+251…`.

## 1. Routes & pages (legacy URL → purpose)

| Legacy URL | Purpose / guard |
|---|---|
| `/dashboard/services` | Service list (OWNER only). Filters `q`/`category`/`status`, rows + Edit + Price-history modal. No pagination. |
| `/dashboard/services/new` | Create-service form (OWNER only). |
| `/dashboard/services/[id]/edit` | Edit-service form + `ServiceStatusButton` + Price-history modal (OWNER only). **There is no `/dashboard/services/[id]` page** (directory only holds `edit`) → detail-only URL 404s. |
| `/sales` | Sale list (OWNER + RECEPTION). OWNER gets filter card, export links, `/sales/voids` link and `sumTotal`; RECEPTION gets none of those and is scoped to own sales for the current clinic day. |
| `/sales/new` | New-sale form (OWNER + RECEPTION). Prefill query params: `patientId`, `doctorId`, `serviceId`, `appointmentId` (resolved by `resolveSalePrefill`; doctor must be `active && isDoctor`, service must be `active`, missing rows → `null`). |
| `/sales/[id]` | Sale detail: receipt, audit trail, void actions (OWNER + RECEPTION). RECEPTION sees the sale **only if `createdBy === actor.id`, else `notFound()` (404)**. `?receipt=1` is set by the post-create redirect (page itself ignores it). |
| `/sales/voids` | Void-requests inbox (OWNER only). |
| `/revenue` | Revenue dashboard (OWNER only). Query: `period`, `from`, `to`. |
| `/api/sales/export` | CSV/XLSX export (OWNER + RECEPTION, same scoping as `/sales`). No session → proxy redirect (307) to secret login; `PASSWORD_CHANGE_REQUIRED` → redirect `/change-password`; wrong role → body `Forbidden`, status 403. Audits `EXPORTED` (entity `sale`). |
| `/dashboard/daily` | OWNER daily summary — consumer of `listRevenueSales`/`buildRevenueReport` (details belong to the dashboard spec). |
| `/dashboard` | Quick links gated `roles: ['OWNER']`: `/revenue` (label `revenue`), `/dashboard/services` (label `services`); stat cards include `/revenue` (`statRevenueWeek`) and `/sales/voids` (`statPendingVoids`). |
| `/patients/[id]` | "Sales" tab = `PatientSalesList` (this patient's CONFIRMED+VOIDED sales) + `countPatientSales` badge (`src/modules/patients/tabs.ts`). |
| `/services` (public site) | Public marketing page via `src/modules/public-site/queries.ts`: only `isPublic: true, active: true`; **price is never published**. (`api/public/services` contains only `[id]/image`; no JSON list route.) |

Page guard helper: `requirePageRole(roles)` → wrong role `redirect('/403')`, forced password change
`redirect('/change-password')`, no session → secret login. Server actions use `withAuth(roles, …)` →
`{ok:false, error:'forbidden'|'passwordChangeRequired'|'validationError', fields?}`.
Smoke script (`scripts/smoke.sh` §11) asserts literal strings: `/sales` contains `2026-0000` and `Total`;
`/sales/new` contains `Confirm sale`; sale detail contains `Sales receipt` and `Sale created`; `/revenue` contains
`Total revenue` and `By service`; CSV starts `receiptNumber,saleDate,patient`; unauthenticated export → 307.

## 2. Pure logic to port (receipt.ts gap-free numbering incl. exact concurrency approach; lines.ts price snapshots incl. exact computation; revenue.ts presets + aggregation incl. rounding; categories.ts fixed category keys + label map; roles.ts)

### 2.1 `src/lib/money.ts` (shared rules — rule 1)
- Decimal-only (`decimal.js`), 2 dp, half-up (`toDecimalPlaces(2)` default = `ROUND_HALF_UP`). Never JS floats.
- `MAX_MONEY = '9999999999.99'` (NUMERIC(12,2) = 10 integer digits + 2 decimals), `ZERO_MONEY='0.00'`.
- `money(v)` normalizes to 2 dp (`'10.5'→'10.50'`, `'1234.567'→'1234.57'`); `isMoneyInRange(v)` = finite ∧ ≥0 ∧ ≤MAX.
- `quantity(v)` requires an integer, else throws `Quantity must be an integer`.
- `addMoney(...)` sum then 2 dp; `multiplyMoney(unit,qty)` = money(unit) × quantity(qty) then 2 dp; `lineTotal` = alias.
- `sumLineTotals([{unitPrice,quantity}])` = Σ multiplyMoney, 2 dp.
- `toDecimalString(v)` = `money(v).toFixed(2)` — the only persistable/serialized form.
- `averageMoney(total,count)`: count ≤ 0 / non-finite → `'0.00'`; else total/count 2 dp half-up.
- `percentChange(current,previous)`: previous zero → `null`; else ((cur−prev)/prev)×100 → 1 dp half-up.
- `formatMoney(v, currency='ETB', locale='en-ET')`: `Intl.NumberFormat` `style:currency`, `currencyDisplay:'code'`,
  fraction digits 2 → e.g. `ETB 1,234.50`.

### 2.2 `receipt.ts` — gap-free per-year numbers, exact concurrency approach
- `RECEIPT_PAD = 6`; format `formatReceiptNumber(year, value)` → `` `${year}-${String(value).padStart(6,'0')}` ``
  (e.g. `2026-000123`). Throws `` Invalid receipt number: ${year}-${value} `` unless `Number.isInteger(year)`
  ∧ `Number.isInteger(value)` ∧ `value >= 1`.
- `receiptYear(date, tz)` = `Number(clinicDateKey(date, tz).slice(0, 4))` — **calendar year in the clinic tz**
  (Addis is UTC+3: `2026-12-31T20:30Z` → 2026; `21:30Z` → 2027).
- **Concurrency (exact)**: one atomic statement per claim, executed **inside the sale transaction**:
  ```sql
  INSERT INTO "ReceiptCounter" ("year", "lastNumber")
  VALUES ($year, 1)
  ON CONFLICT ("year") DO UPDATE SET "lastNumber" = "ReceiptCounter"."lastNumber" + 1
  RETURNING "lastNumber"
  ```
  Postgres takes a row lock on the conflicting `ReceiptCounter` row, so concurrent claims serialize and never
  share a number; because it runs in the same transaction as `sale.create`, a failed/rolled-back sale rolls the
  counter back too → **no gaps**. `nextReceiptNumber(tx, now, tz)` = claim + format in one call.
- Laravel port: identical SQL via `DB::selectOne(...)`/`DB::statement` inside `DB::transaction` that also creates
  the sale (Postgres, so `ON CONFLICT … RETURNING` is available); `Sale.receiptNumber` also `UNIQUE` as a backstop.
  Table: `receipt_counters(year INT PK, last_number INT DEFAULT 0)`.

### 2.3 `lines.ts` — server-side price snapshots, exact computation
- Input: `ServiceSnapshot[] {id,name,currentPrice,active}` + `RequestedLine[] {serviceId,qty}` — **client sends only
  `serviceId` + `qty`**; prices/names come from DB rows, tampered client prices are impossible (never read).
- `mergeRequested`: `Map<serviceId, sum(qty)>` (first-seen order preserved) → duplicates become one line.
- Per merged line, in order: unknown id → collect into `missing` (skip); `!active` → collect `inactive` name (skip);
  `qty < 1 || qty > MAX_QTY(999)` → **immediate abort** `{ok:false,error:'quantityTooHigh',serviceNames:[name]}`.
- Line output: `unitPrice = toDecimalString(multiplyMoney(currentPrice, 1))` (normalize DB price to 2 dp),
  `lineTotal = toDecimalString(multiplyMoney(currentPrice, qty))`, `serviceNameSnapshot = service.name`.
- Error precedence after the loop: `inactive.length>0` → `{error:'serviceInactive', serviceNames:inactive}`;
  else `missing.length>0` → `{error:'serviceMissing', serviceNames:missing}`; else `lines.length===0` →
  `{error:'serviceMissing', serviceNames:[]}`.
- `total = toDecimalString(sumLineTotals(lines))`.

### 2.4 `revenue.ts` — presets + aggregation + rounding
- Only `CONFIRMED` sales are revenue; `VOIDED` totaled separately; `DRAFT` ignored entirely. Day buckets use
  `clinicDateKey(saleDate, tz)` — never server-local time.
- Per confirmed sale: `byDay[dayKey] += total`; buckets keyed/labelled: **service → `line.serviceName` (the sale
  snapshot, not id)**, **category → `line.category` (joined live from `Service.category` at report time — not
  snapshotted)**, doctor → `sale.doctorName ?? ''` label `sale.doctorName ?? '—'`, payment → raw enum value
  (`CASH`…, label translated at render).
- Bucket rounding: `Decimal.toDecimalPlaces(2)`; bucket sort: **total desc**, tie → `key.localeCompare` asc.
  `byDay` sorted `dateKey` asc; bucket `count` = number of contributing entities (sales for doctor/payment/day,
  **lines** for service/category).
- `totalRevenue = toDecimalString(addMoney(...confirmedTotals))`; `salesCount = confirmed.length`;
  `averageSale = averageMoney(totalRevenue, count)`; `voidedTotal = toDecimalString(addMoney(...voidedTotals))`;
  `voidedCount = voided.length`.
- `fillDailyTrend(byDay, fromKey, toKey)`: walks `dateKeyAdd(+1)` inclusive, `{dateKey,total:'0.00',count:0}` for
  missing days, loop guard `i <= 400` (max 401 entries).
- `PERIOD_PRESETS = ['today','week','month','lastMonth','custom']`; `isPeriodPreset(v)` = string ∈ that list.
- `resolvePeriod(preset, now, tz, {from?,to?})` → `{current, previous}` (clinic-tz calendar days):
  - `today` (default): `{today,today}`.
  - `week`: `weekday = Date.parse(today+'T00:00:00Z').getUTCDay()` (0=Sunday), `sinceMonday = (weekday+6)%7`,
    `current = {from: today−sinceMonday, to: today}` (Mon…today).
  - `month`: `current = {from: YYYY-MM-01, to: today}`.
  - `lastMonth`: **special case** — `current = monthRange(prevYear,prevMonth)`, `previous = monthRange(before)`;
    January rolls to December of the previous year.
  - `custom`: `from`/`to` must match `/^\d{4}-\d{2}-\d{2}$/`, else fall back to `today`; if `from > to` swap.
  - For all non-`lastMonth` presets: `previous = previousEquivalent(current)` where
    `length = Math.round((to−from)/86_400_000)+1` (UTC date-only math), `previous.to = current.from − 1`,
    `previous.from = previous.to − (length−1)` (equal-length preceding span).
- `inRange(dateKey, range)` = inclusive string comparison `from <= k <= to`.
- `percentChange` re-exported from money (1 dp half-up; `null` when previous = 0 → UI shows `—`).

### 2.5 `categories.ts` — fixed keys + label map
- `SERVICE_CATEGORIES` (exact order): `consultation`, `refraction`, `diagnostics`, `tests`, `glasses`, `optical`,
  `contact_lenses`, `specialist`, `surgery`, `other`. Stored as a plain string column (new keys need no migration).
- `CATEGORY_LABEL_KEYS` (namespace `services`): `consultation→catConsultation`, `refraction→catRefraction`,
  `diagnostics→catDiagnostics`, `tests→catTests`, `glasses→catGlasses`, `optical→catOptical`,
  `contact_lenses→catContactLenses`, `specialist→catSpecialist`, `surgery→catSurgery`, `other→catOther`.
- `isServiceCategory(v)` = string ∈ list.

### 2.6 `roles.ts` (both modules) + display helpers
- `src/modules/services/roles.ts`: `SERVICES_ROLES = ['OWNER']` — "**Service management (prices) is OWNER-only.**"
- `src/modules/sales/roles.ts`: `SALES_ROLES = ['OWNER','RECEPTION']` (create/confirm sales);
  `SALES_OWNER_ROLES = ['OWNER']` (revenue, void approval, direct voids); predicates (role may be null/undefined):
  - `canViewRevenue(role)` → `role === 'OWNER'`
  - `canApproveVoid(role)` → `role === 'OWNER'`
  - `canVoidDirectly(role)` → `role === 'OWNER'`
  - `canRequestVoid(role)` → `role === 'OWNER' || role === 'RECEPTION'`
  - `canSeeAllSales(role)` → `role === 'OWNER'`
- `src/modules/services/display.ts` (pure): `serviceNameIn(locale, {name,nameEn,nameAm})` — `am` → `nameAm ?? nameEn
  ?? name`; else `nameEn ?? name`. `receiptLabelIn(locale, {receiptLabelEn,receiptLabelAm,fallback})` — `am` →
  `labelAm ?? labelEn ?? fallback`; else `labelEn ?? labelAm ?? fallback` (fallback = sale-time name snapshot).

## 3. Validation schema per form (service form, sale form, void request — fields, rules, error keys)

All schemas carry **i18n keys** as messages; the server wraps failures as
`{error:'validationError', fields:{<path>: '<key>'}}` (first issue per path wins; path joined with `.`, empty → `_`).
Client renders `validation.<key>` per field, `common.validationError` globally (`ActionError`).

### 3.1 Service form — `serviceFormSchema` (max lengths are exact)
| Field | Rules | Error keys |
|---|---|---|
| `nameEn` | trim, 1..120 (`MAX_SERVICE_NAME`) | `required`, `tooLong` |
| `nameAm` | trim, ≤120, optional | `tooLong` |
| `category` | enum of the 10 fixed keys | `invalidCategory` |
| `currentPrice` | trim, ≥1 char, must match `/^\d{1,10}(\.\d{1,2})?$/` **and** `isMoneyInRange` | `required`, `invalidPrice` |
| `isPublic` | string, optional, `value === 'true'` (absent/unchecked → `false`) | — |
| `publicDescriptionEn`/`publicDescriptionAm` | trim, ≤2000 (`MAX_PUBLIC_DESCRIPTION`), `\|\| null` | `tooLong` |
| `receiptLabelEn`/`receiptLabelAm` | trim, ≤120, `\|\| null` | `tooLong` |
| `displayOrder` | default `'0'`; `Number()` finite ∧ ≥0 → `Math.floor`, else `0` | — |
| `imageUrl` | trim, ≤500 (`MAX_IMAGE_URL`), `\|\| null` | `tooLong` |
| `image` | optional `File`; MIME ∈ `{image/png:png, image/jpeg:jpg, image/webp:webp}`; size ≤ `1024*1024` (1 MiB) | `invalid`, `imageInvalidType`, `imageTooLarge` |

Server extras: `createServiceAction` sets `name = nameEn`, `active: true`; uploaded file stored as
`/api/uploads/service-<uuid>.<ext>`; on update with a new image the old storage key is removed best-effort
(errors swallowed). `readServiceForm` also reads `name` but zod strips it (unknown key). Price change detection is
string-level: `toDecimalString(before.currentPrice) !== toDecimalString(updated.currentPrice)`.

### 3.2 Sale form — `saleFormSchema` / `saleLineSchema` / server checks
- `saleLineSchema`: `serviceId` string 1..64 (`required`,`tooLong`); `qty` int, `min 1` (`invalidQuantity` for
  non-int, `minQuantity`), `max 999` (`tooMany`).
- `saleFormSchema`: `patientId` ≤64 (`tooLong`); `doctorId` ≤64 (`tooLong`); `paymentMethod` enum
  `['CASH','MOBILE_MONEY','BANK','CARD','OTHER']` (`invalidPaymentMethod`); `lines` array 1..50
  (`noLines` / `tooManyLines`); `walkIn` boolean (form field is the string `'true'`/`'false'`).
- Server-side after zod (`createSaleAction`):
  - `!walkIn ∧ !patientId` → `fields.patientId = 'required'`; patient lookup must exist **and be `active`** →
    else `fields.patientId = 'invalid'`. Walk-in ⇒ `patientId = null`.
  - `doctorId` present → `Staff` must exist and be `active` → else `fields.doctorId = 'invalid'` (note: the sale
    action checks `active` only; the **prefill** resolver additionally requires `isDoctor: true`).
  - Line recompute (§2.3): any compute failure → `fields.lines = 'serviceMissing' | 'serviceInactive' |
    'quantityTooHigh'`. Client prices/names are never read.
  - `lines` field itself is a JSON string (`JSON.parse` failure → `[]` → `noLines`).
- `saleFilterSchema` (defined, **unused** — `/sales` and the export route parse query params manually with the
  same rules): `from`/`to` regex `/^\d{4}-\d{2}-\d{2}$/` (`invalidDate`), `patient` ≤100 (`tooLong`), `serviceId`
  ≤64, `doctorId` ≤64, `status` enum DRAFT/CONFIRMED/VOIDED (`invalid`), `paymentMethod` enum (`invalid`).

### 3.3 Void reason — `voidReasonSchema` (used by request / direct void / reject)
- `reason`: `z.string().trim().min(3, 'required').max(300, 'tooLong')` (`MAX_REASON = 300`).
  → error keys `required` (<3 chars trimmed) and `tooLong` (>300), surfaced as
  `{error:'validationError', fields:{reason:…}}`. The client modal also enforces HTML
  `required minlength="3" maxlength="300"`.
- Approve uses **no reason** (modal has none) — it reuses the requester's `voidRequestReason`.
- Domain errors returned as whole-action `error` keys (rendered from `sales.*`): `notConfirmed`, `alreadyRequested`
  (`requestVoidAction` only), `noRequest` (approve/reject only); plus `common.*`: `forbidden`, `unknownError`,
  `validationError`, `passwordChangeRequired`.

## 4. Role matrix (page/action/API × OWNER/RECEPTION/STAFF)

| Surface | OWNER | RECEPTION | STAFF |
|---|---|---|---|
| `/dashboard/services*` (pages) | ✅ | ❌ → `/403` | ❌ → `/403` |
| `createServiceAction` / `updateServiceAction` / `setServiceActiveAction` / `getPriceHistoryAction` | ✅ | ❌ `forbidden` | ❌ `forbidden` |
| `/sales`, `/sales/new` (pages) | ✅ | ✅ | ❌ → `/403` |
| `/sales/[id]` (page) | ✅ any sale | ✅ **own sale only** (`createdBy === actor.id`, else 404) | ❌ → `/403` |
| `createSaleAction` | ✅ | ✅ | ❌ `forbidden` |
| `searchPatientsAction` (type-ahead) | ✅ | ✅ | ❌ `forbidden` |
| `/sales/voids` (page) | ✅ | ❌ → `/403` | ❌ → `/403` |
| `voidSaleAction` (direct void) | ✅ | ❌ `forbidden` | ❌ `forbidden` |
| `approveVoidRequestAction` / `rejectVoidRequestAction` | ✅ | ❌ `forbidden` | ❌ `forbidden` |
| `requestVoidAction` | ✅ | ✅ | ❌ `forbidden` |
| `/revenue` (page) | ✅ | ❌ → `/403` (e2e asserts forbidden) | ❌ → `/403` |
| `/api/sales/export` | ✅ full list | ✅ **scoped: own + current clinic day** (`scopeToActor` overrides caller `from`/`to`/`createdBy`) | ❌ 403 |
| `/dashboard/daily` | ✅ | ❌ → `/403` | ❌ → `/403` |
| Revenue visibility (nav/dashboard links, stats, `sumTotal`) | ✅ | ❌ hidden (`canViewRevenue`) | ❌ hidden |
| Sales list filters card + export links + `/sales/voids` button | ✅ shown | ❌ not rendered | n/a |
| STAFF financial visibility | — | — | **none** (no sales/revenue surface at all) |

- `scopeToActor(filters, actor, todayKey)`: `canSeeAllSales(role)` → filters untouched; else
  `{...filters, createdBy: actor.id, from: todayKey, to: todayKey}` (forces the current clinic day).
- `listVoidRequests()` is only reachable from OWNER pages, but has no internal role check (guarded by page).

## 5. Audit actions (exact strings, entity names, diffed fields)

`writeAudit({user, entity, entityId, action, old?, new?})` → `AuditLog` row (field-level diff; secrets redacted).

| Action string | Entity | `old` | `new` / notes |
|---|---|---|---|
| `SALE_CREATED` | `Sale` (sale.id) | — | `receiptNumber`, `patientId`, `doctorId`, `paymentMethod`, `status`, `total` (`toDecimalString`), `lines[]: {service, qty, unitPrice, lineTotal}` where the three money values are rendered with `formatMoney` (default `ETB …`, unlike `total`). Written after the transaction commits. |
| `SALE_VOID_REQUESTED` | `Sale` | — | `voidRequestedBy`, `voidRequestReason` |
| `SALE_VOIDED` | `Sale` | `status: 'CONFIRMED'` | `status: 'VOIDED'`, `voidedBy`, `voidReason` (direct OWNER void) |
| `SALE_VOID_APPROVED` | `Sale` | `status: 'CONFIRMED'`, `voidRequestedBy` | `status: 'VOIDED'`, `voidedBy` (approver), `voidReason` (= requester's reason) |
| `SALE_VOID_REQUEST_REJECTED` | `Sale` | `voidRequestedBy`, `voidRequestReason` | `rejectReason` (OWNER's text — **only stored in the audit**, the sale row just clears both request fields) |
| `SERVICE_CREATED` | `Service` | — | `name`, `nameAm`, `category`, `currentPrice` (`toDecimalString`), `active`, `isPublic` |
| `SERVICE_UPDATED` | `Service` | `name`, `nameAm`, `category`, `currentPrice`, `isPublic` | same keys with new values |
| `SERVICE_PRICE_CHANGED` | `Service` | `currentPrice` (before) | `currentPrice` (after) — only when the normalized price string differs; **also** inserts a `ServicePriceHistory{serviceId, oldPrice, newPrice, changedBy}` row (same condition) |
| `SERVICE_STATUS_CHANGED` | `Service` | `active` | `active` — skipped entirely when the flag is unchanged (action returns `ok` early) |
| `EXPORTED` | `sale` (`entityId: 'export'`) | — | `{…filters minus page, format: 'csv'\|'xlsx'}` from `auditExport` on `/api/sales/export` |

Audit display: `listSaleAudit(saleId)` = `AuditLog where entity='Sale' AND entityId=saleId`, `createdAt desc`,
`take 100`, with user names. Sale-detail label map (namespace `sales`): `SALE_CREATED→auditCreated`,
`SALE_VOID_REQUESTED→auditVoidRequested`, `SALE_VOID_APPROVED→auditVoidApproved`,
`SALE_VOID_REQUEST_REJECTED→auditVoidRejected`, `SALE_VOIDED→auditVoided`; unknown actions print the raw string.
Price history UI reads `ServicePriceHistory` (not AuditLog): `order changedAt desc`, `take 100`.

## 6. Business rules & invariants (numbered, exact)

1. **Decimals, never floats.** Every money value is `NUMERIC(12,2)` / Decimal, 2 dp half-up; serialize with
   `toFixed(2)`; only integers are accepted as quantities. Max amount `9999999999.99`, non-negative.
2. **Server recomputes prices — client never trusted.** The client submits only `{serviceId, qty}` per line plus
   `patientId|walkIn`, `doctorId`, `paymentMethod`; unit prices, line totals and the sale total are recomputed from
   DB rows inside `createSaleAction`. A tampered price field is impossible to inject (it is not read).
3. **Confirmed sales are immutable.** No update/delete endpoint exists for `Sale`/`SaleLine`; the only transition
   is `CONFIRMED → VOIDED`, via direct OWNER void, or request (OWNER/RECEPTION) → OWNER approve. Rejection keeps
   the sale `CONFIRMED`. Deletion never happens (no hard deletes anywhere; soft `active` flags instead).
4. **Gap-free per-year receipt numbers.** Format `2026-000123` (`<clinic-year>-<6 digits>`); year = calendar year
   of the sale instant **in the clinic timezone**; claimed with the atomic upsert §2.2 inside the sale
   transaction; `receiptNumber` is `UNIQUE`; rollback of the sale frees the number → no gaps, no reuse,
   concurrency-safe.
5. **Price snapshot semantics.** `SaleLine` stores `serviceNameSnapshot`, `unitPrice`, `lineTotal` at sale time.
   Later price changes (`ServicePriceHistory` + `SERVICE_PRICE_CHANGED`) or renames never touch existing lines.
   Receipt short labels (`receiptLabelEn/Am`) are read live from `Service` with fallback to the snapshot name;
   `Service.category` is also read live (revenue `byCategory` reflects today's categorisation).
6. **Deactivated services** (`active=false`) disappear from the new-sale picker (`listActiveServices` filters
   `active: true`) and are rejected at compute time (`serviceInactive`), but stay in sale history, in lists (as
   "Inactive" badge) and in revenue breakdowns.
7. **Sale statuses** `DRAFT | CONFIRMED | VOIDED` (Prisma enum default `DRAFT`); this module always **creates
   sales directly as `CONFIRMED`** (`confirmedAt = now`) — DRAFT has no creation path here but stays in the
   enum, list filter and labels. **Payment methods** `CASH | MOBILE_MONEY | BANK | CARD | OTHER` (UI default
   `CASH`).
8. **Void flow rules**: only `CONFIRMED` sales (`notConfirmed` otherwise); at most one pending request
   (`alreadyRequested` if `voidRequestedBy` set); approve/reject require a pending request (`noRequest`); reason
   3..300 chars trimmed; approve reuses the requester's reason and sets `voidedBy = approver`, `voidedAt = now`;
   reject clears `voidRequestedBy`/`voidRequestReason` and stores the reject text only in the audit.
9. **RECEPTION scoping**: sees only own sales of the current clinic day (`scopeToActor` forcibly overwrites
   `createdBy`, `from`, `to`), no cross-day/aggregate totals (`sumTotal` and the filter card are hidden), and no
   revenue anywhere. **Revenue, void-approval and direct-void are OWNER-only** (`SALES_OWNER_ROLES`).
10. **Time**: `saleDate = new Date()` (server, UTC); all day bucketing, receipt years, list filters and period
    presets use the clinic timezone from settings (default `Africa/Addis_Ababa`); `startOfDay` is inclusive,
    `endOfDay` is the exclusive next-local-midnight instant.
11. **Caps**: ≤ 50 lines per sale (`MAX_SALE_LINES`), qty 1..999 (`MAX_QTY`), duplicate service rows merge by
    summed qty (server + client), patient search query ≤ 100 chars (`MAX_PATIENT_QUERY`).
12. **Referential guards on sale creation**: patient must exist + `active` unless walk-in; optional doctor must
    exist + `active`; each referenced service must exist + `active`.
13. **Services**: `name` (legacy English) is kept in sync with `nameEn` on every write; category is validated
    against the fixed key list; image upload MIME-whitelisted, ≤ 1 MiB (enforced by schema; the
    `validation.imageTooLarge` copy still says "2 MB" — code wins); the public site never exposes `currentPrice`.
14. **Empty/zero safety**: `averageMoney` with 0 sales → `0.00` (no division by zero); `percentChange` with a zero
    previous period → `null` (UI shows `—`); VOIDED totals are excluded from revenue but always reported
    separately (card + `voidedCount`).

## 7. Queries: list filters/sort whitelist/pagination caps/export CSV columns+filename; revenue period presets and breakdowns; previous-period %; VOIDED handling

**Sales list (`listSales`)** — shared `buildSaleWhere(filters, tz)`:
- Whitelisted filters (validated at the page/export boundary): `from`/`to` (`/^\d{4}-\d{2}-\d{2}$/`; `from` →
  `saleDate >= startOfDay(from,tz)`, `to` → `saleDate < endOfDay(to,tz)`), `patient` (≤100, `OR` patient
  `fullName contains insensitive` ∥ `phoneE164 contains` each `phoneSearchVariants`), `serviceId` (≤64,
  `lines.some.serviceId`), `doctorId` (≤64), `status` ∈ enum, `paymentMethod` ∈ enum, `createdBy` (set only by
  `scopeToActor`). Unknown/out-of-range params are dropped (enum checks, `slice`, date regex).
- **Sort whitelist: none — fixed** `orderBy: [{saleDate:'desc'},{id:'desc'}]` (no user sort parameter).
- Pagination: `SALES_PAGE_SIZE = 20`; `pageCount = max(1, ceil(total/20))`; `page = clamp(Number(page)||1, 1,
  pageCount)`; `skip=(page-1)*20`.
- `sumTotal`: normally `SUM(Sale.total)` over the filter; **when `serviceId` is filtered**, sums
  `SaleLine.lineTotal` for that service across sales matching the filter *without* `serviceId` (a sale's whole
  total must not be credited to one service).
- Row payload: id, receiptNumber, saleDate, status, paymentMethod, total (string), createdBy + resolved
  `createdByName`, patient{id,fullName}|null, doctor{id,fullName}|null, `lineCount` (`_count.lines`).
- **Export (`listSalesForExport`)**: same where/orderBy, `take: SALES_EXPORT_CAP = 5000` (hard cap, no paging).
  Headers exactly `receiptNumber,saleDate,patient,doctor,paymentMethod,status,lines,total,createdBy`; values:
  ISO saleDate, patient/doctor names or `''`, raw enum status/payment, `lineCount` for `lines`, `toDecimalString`
  total, creator name or `''`. Filename `sales-YYYY-MM-DD.csv` (xlsx: same stem `.xlsx`); audited `EXPORTED`.
- `listVoidRequests`: `status='CONFIRMED' AND voidRequestedBy ≠ null`, `orderBy updatedAt asc`, joined requester
  name + patient name.
- `countPatientSales`: `patientId` ∧ `status ∈ {CONFIRMED,VOIDED}`. `listPatientSales(patientId, limit=50)`:
  same status filter, `saleDate desc`; plus `confirmedTotal = SUM(total) where status='CONFIRMED'`.
- `listDoctorOptions`: `Staff.active=true`, `fullName asc`. `listSales`/detail resolve creator/void user names in
  a second `User.findMany`.

**Services (`queries.ts`)**:
- `buildServiceWhere`: `q` (≤100, `OR name / nameAm / nameEn contains insensitive`), `category` (only if
  `isServiceCategory`), `status` `'true'→active:true` / `'false'→active:false`.
- `listServices`: fixed `orderBy [{active:'desc'},{name:'asc'}]`, **no pagination**.
- `listActiveServices`: `active:true`, `orderBy [{displayOrder:'asc'},{name:'asc'}]` (new-sale picker).
- `listServiceOptions`: all services `{id,name,nameEn}`, `name asc` (list filter dropdown).
- `listPriceHistory`: `serviceId`, `changedAt desc`, `take 100` (+ changer names).

**Revenue (`revenue/page.tsx` + `listRevenueSales`)**:
- Preset from `period` (`isPeriodPreset`, else `today`); `from`/`to` validated `/^\d{4}-\d{2}-\d{2}$/` for
  `custom` (invalid → today; reversed → swapped).
- One fetch spanning the union: `listRevenueSales(startOfDay(previous.fromKey, tz), endOfDay(current.toKey, tz))`
  → `status ∈ {CONFIRMED, VOIDED}`, `saleDate >= from AND < to` (instants), selecting sale + lines
  (`serviceNameSnapshot`, `qty`, `lineTotal`) + `service.category` + `doctor.fullName`. **DRAFT is never fetched.**
- The page then splits in memory: `inRange(clinicDateKey(saleDate,tz), current)` / `… previous` →
  `buildRevenueReport` for each; `change = percentChange(current.totalRevenue, previous.totalRevenue)`.
- Breakdowns: by service (snapshot name), by category (live key, translated), by doctor, by payment method — each
  a table of `{label, count, total}` sorted total desc (tie: key asc). Daily trend = `fillDailyTrend` zero-filled
  over the current range.
- **VOIDED handling**: excluded from `totalRevenue`, `salesCount`, `averageSale` and every breakdown; reported as
  `voidedTotal` + `voidedCount` (red card). Period presets: `today`, `week` (Mon..today), `month` (1st..today),
  `lastMonth` (full prior calendar month, previous = month before), `custom` (equal-length previous span).

## 8. i18n keys used (complete list)

Files: `messages/en.json` + `messages/am.json` (keys mirror EN; parity enforced by `npm run messages`).
Field errors render `validation.<key>`; global action errors render `common.<key>`.

- **`services.*`** (54): `title, newService, editService, searchPlaceholder, name, nameAm, nameAmHint, category,
  chooseCategory, currentPrice, priceHint, priceChangeNote, priceHistory, priceHistoryEmpty, oldPrice, newPrice,
  changedBy, changedAt, create, backToList, activeHint, inactiveHint, deactivate, deactivateConfirm, reactivate,
  reactivateConfirm, catConsultation, catRefraction, catDiagnostics, catTests, catGlasses, catOptical,
  catContactLenses, catSpecialist, catSurgery, catOther, publicTitle, publicLabel, publicHint,
  publicDescriptionEn, publicDescriptionAm, publicDescriptionHint, nameEn, nameEnHint, receiptLabelEn,
  receiptLabelAm, receiptLabelsHint, displayOrder, displayOrderHint, imageUrl, imageUrlHint, image, imageHint,
  toBeProvided`.
- **`sales.*`** (91): `title, newSale, confirmSale, patient, walkIn, walkInHint, patientSearchPlaceholder,
  patientFilterPlaceholder, services, service, serviceSearchPlaceholder, unitPrice, qty, lineTotal, noLines,
  doctor, noDoctor, paymentMethod, total, totalsHint, date, from, to, status, statusDraft, statusConfirmed,
  statusVoided, payCash, payMobileMoney, payBank, payCard, payOther, receiptNumber, receiptTitle, receiptFooter,
  printA5, printThermal, voidedWatermark, exportCsv, sumTotal, reason, view, patientNoSales, patientSalesCount,
  patientTotal, notConfirmed, alreadyRequested, noRequest, voidSale, voidSaleTitle, voidSaleBody, requestVoid,
  requestVoidTitle, requestVoidBody, voidRequests, requestedBy, approveVoid, approveVoidTitle, approveHint,
  rejectVoid, rejectVoidTitle, noActions, voidRequestPending, voidRequestedBy, voidedMeta, createdBy, createdAt,
  auditTrail, auditCreated, auditVoidRequested, auditVoidApproved, auditVoidRejected, auditVoided, revenueTitle,
  periodToday, periodWeek, periodMonth, periodLastMonth, periodCustom, totalRevenue, salesCount, averageSale,
  voidedTotal, voidedCount, vsPrevious, dailyTrend, name, byService, byCategory, byDoctor, byPaymentMethod`.
- **`validation.*` (subset used here)**: `required, invalid, tooLong, invalidCategory, invalidPrice,
  invalidQuantity, minQuantity, tooMany, noLines, tooManyLines, invalidPaymentMethod, invalidDate,
  imageInvalidType, imageTooLarge, serviceInactive, serviceMissing, quantityTooHigh`.
- **`common.*` (subset used here)**: `save, saving, cancel, delete, edit, search, loading, empty, noResults,
  close, back, actions, status, all, active, inactive, page, showing, previous, next, filter, clear, of,
  unknownError, forbidden, validationError, passwordChangeRequired`.
- **`export.*`**: `formatCsv` ("CSV"), `formatXlsx` ("Excel") — the two export links.
- Interpolations: `common.showing` `{from}{to}{total}`; `sales.sumTotal` `{total}`;
  `sales.patientSalesCount`/`sales.voidedCount` `{count}`; `sales.patientTotal` `{total}`;
  `sales.vsPrevious` `{change}`; `sales.voidRequestedBy` `{name}`; `sales.voidedMeta` `{name}{date}`.
- Also referenced by neighbouring surfaces (same keys, for parity): nav label `revenue`/`services`
  (`dashboard.*` `statRevenueWeek`, `statPendingVoids`).

## 9. Test inventory (test file → test name → exact assertions; enough to re-implement in PHPUnit)

### `src/lib/money.test.ts` — `describe('money')`
1. `normalizes to 2 decimal places`: `money('10.5')→'10.50'`, `money('0')→'0.00'`, `money('1234.567')→'1234.57'`
   (all via `toDecimalString`).
2. `adds without float errors`: `addMoney('0.10','0.20')→'0.30'`; `addMoney('19.99','0.01','100')→'120.00'`;
   `addMoney()→'0.00'`.
3. `multiplies unit price by quantity`: `multiplyMoney('199.99',3)→'599.97'`; `lineTotal('50.00',2)→'100.00'`.
4. `sums sale line totals`: `sumLineTotals([{100.00,2},{33.33,3}])→'299.99'`.
5. `rejects fractional quantities`: `quantity(1.5)` throws; `quantity('2.5')` throws.
6. `formats with the clinic currency (ETB default)`: `formatMoney('1234.5')` contains `ETB` and `1,234.50`.
7. `rounds half-up at the third decimal`: `money('0.005')→'0.01'`, `money('0.004')→'0.00'`,
   `multiplyMoney('0.005',1)→'0.01'`, `multiplyMoney('1.005',1)→'1.01'`, `addMoney('0.005','0.005')→'0.01'`.
8. `handles large NUMERIC(12,2) values exactly`: `addMoney('9999999999.99','0')→'9999999999.99'`;
   `money('9999999999.994')→'9999999999.99'`; `isMoneyInRange('9999999999.99')===true`;
   `isMoneyInRange(MAX_MONEY)===true`; `isMoneyInRange('10000000000.00')===false`;
   `isMoneyInRange('-1')===false`; `isMoneyInRange('abc')===false`.
9. `averages money with half-up rounding`: `averageMoney('100.00',4)→'25.00'`; `('100.00',3)→'33.33'`;
   `('0.05',2)→'0.03'`; `('500.00',0)→'0.00'`.
10. `computes percentage change, null when previous is zero`: `percentChange('150.00','100.00')===50`;
    `('50.00','100.00')===-50`; `('100.00','0')===null`; `('133.33','100.00')===33.3`.

### `src/modules/sales/receipt.test.ts` — `TZ = 'Africa/Addis_Ababa'`
- `describe('formatReceiptNumber')`
  1. `pads to 6 digits`: `RECEIPT_PAD===6`; `formatReceiptNumber(2026,1)==='2026-000001'`;
     `(2026,123)==='2026-000123'`; `(2026,999999)==='2026-999999'`.
  2. `rejects invalid values`: `(2026,0)` throws; `(2026.5,1)` throws; `(2026,1.5)` throws.
- `describe('receiptYear')`
  3. `uses the clinic timezone (23:30 Addis is still the same year)`:
     `receiptYear(2026-12-31T20:30:00.000Z, TZ)===2026`; `receiptYear(2026-12-31T21:30:00.000Z, TZ)===2027`.

### `src/modules/sales/lines.test.ts` — `describe('computeSaleLines')`
Fixtures: `consultation {id:'svc-1', name:'Eye Consultation', currentPrice:'300.00', active:true}`,
`oct {id:'svc-2', name:'OCT Scan', currentPrice:'1500.50', active:true}`.
1. `uses the server price snapshot, never a client value`: input `[{svc-1,2},{svc-2,1}]` →
   `ok===true`; `lines[0]` matches `{serviceNameSnapshot:'Eye Consultation', unitPrice:'300.00', qty:2,
   lineTotal:'600.00'}`; `total==='2100.50'`.
2. `merges duplicate service rows by summing quantities`: `[{svc-1,1},{svc-1,2}]` → `ok`,
   `lines.length===1`, `lines[0].qty===3`, `lines[0].lineTotal==='900.00'`.
3. `keeps the price frozen at sale time (later price changes do not apply)`: compute with `300.00` →
   `unitPrice '300.00'`; then with `{...consultation, currentPrice:'400.00'}` → new result
   `unitPrice '400.00'` while the first result still `'300.00'`.
4. `rejects inactive services`: `{...consultation, active:false}` + qty 1 → `{ok:false,
   error:'serviceInactive'}`.
5. `rejects services that no longer exist`: serviceId `'missing'` → `{ok:false, error:'serviceMissing'}`.
6. `rejects quantities below 1 or above the cap`: qty `0` → `{ok:false, error:'quantityTooHigh'}`;
   qty `1000` → same.

### `src/modules/sales/revenue.test.ts`
Fixture helper `sale({id,total,status})` defaults: `saleDate 2026-10-08T09:00:00.000Z`, `paymentMethod 'CASH'`,
`doctorName null`, one line `{serviceName:'Eye Consultation', category:'consultation', qty:1, lineTotal:total}`.
- `describe('buildRevenueReport')` (TZ `Africa/Addis_Ababa`)
  1. `counts only CONFIRMED as revenue and reports VOIDED separately`: inputs CONFIRMED 300.00 + 500.00,
     VOIDED 900.00, DRAFT 1500.00 → `totalRevenue '800.00'`, `salesCount 2`, `averageSale '400.00'`,
     `voidedTotal '900.00'`, `voidedCount 1`.
  2. `breaks revenue down by service, category, doctor and payment method`: two CONFIRMED CARD sales by
     `Dr. Abebe` (1000.00 OCT/diagnostics + 300.00 consultation) →
     `byService keys === ['OCT Scan','Eye Consultation']` (total-desc order), `byService[0].total==='1000.00'`,
     `byCategory` bucket `consultation` total `'300.00'`, `byDoctor[0]` matches `{key:'Dr. Abebe',
     total:'1300.00', count:2}`, `byPaymentMethod[0]` matches `{key:'CARD', total:'1300.00'}`.
  3. `buckets days in the clinic timezone (23:30 Addis stays on the same day)`: sales at `2026-10-08T20:30Z`
     (100.00) and `21:30Z` (200.00) → `byDay['2026-10-08'] total '100.00'`, `byDay['2026-10-09'] total '200.00'`.
- `describe('fillDailyTrend')`
  4. `zero-fills missing days`: `[{2026-10-02,50.00,1}]` over `2026-10-01..2026-10-03` → exactly
     `[{10-01,'0.00',0},{10-02,'50.00',1},{10-03,'0.00',0}]`.
- `describe('resolvePeriod')` (`now = 2026-10-08T12:00:00.000Z`, Thursday)
  5. `resolves today with yesterday as the previous period`: current `{10-08,10-08}`, previous `{10-07,10-07}`.
  6. `resolves the current week from Monday`: current `{10-05,10-08}`, previous `{10-01,10-04}`.
  7. `resolves the month and the previous equivalent span`: current `{10-01,10-08}`, previous `{09-23,09-30}`.
  8. `resolves last month, handling the January boundary`: current `{2026-09-01,2026-09-30}`, previous
     `{2026-08-01,2026-08-31}`; for `2026-01-15` current `{2025-12-01,2025-12-31}`, previous
     `{2025-11-01,2025-11-30}`.
  9. `falls back to today for invalid custom ranges`: `{from:'nope', to:''}` → current `{2026-10-08,2026-10-08}`.
  10. `swaps reversed custom ranges`: `{from:'2026-10-05', to:'2026-10-01'}` → current `{10-01,10-05}`.
- `describe('inRange / percentChange')`
  11. `checks inclusive ranges`: `inRange('2026-10-03', {10-01..10-05})===true`;
      `inRange('2026-09-30', …)===false`.
  12. `re-exports percentChange`: `percentChange('110.00','100.00')===10`.

### `src/modules/sales/roles.test.ts` — `describe('sales role predicates')`
1. `shows revenue only to the OWNER`: `canViewRevenue('OWNER')===true`; `('RECEPTION')===false`; `('STAFF')===false`.
2. `lets only the OWNER approve or void directly`: `canApproveVoid('OWNER')===true`,
   `canApproveVoid('RECEPTION')===false`, `canVoidDirectly('OWNER')===true`,
   `canVoidDirectly('RECEPTION')===false`.
3. `lets OWNER and RECEPTION request a void, never STAFF`: `canRequestVoid('OWNER')===true`,
   `('RECEPTION')===true`, `('STAFF')===false`.
4. `scopes sales visibility to the OWNER`: `canSeeAllSales('OWNER')===true`, `('RECEPTION')===false`,
   `('STAFF')===false`.

### `src/modules/services/display.test.ts`
- `describe('serviceNameIn')` — base `{name:'Legacy', nameEn:'English', nameAm:'አማርኛ'}`:
  1. `it.each([['en','English'],['am','አማርኛ']])('uses the %s name when present', …)`;
  2. `falls back to nameEn without an Amharic name`: `serviceNameIn('am',{…,nameAm:null})==='English'`;
  3. `falls back to name when nameEn is missing`: `serviceNameIn('en',{name:'Legacy',nameEn:null,nameAm:null})
     ==='Legacy'`.
- `describe('receiptLabelIn')` — labels `{receiptLabelEn:'Check-up', receiptLabelAm:'ክትትል', fallback:'Long name'}`:
  4. `it.each([['en','Check-up'],['am','ክትትል']])('uses the %s label when present', …)`;
  5. `falls back to the other language label for am`: am with `receiptLabelAm:null` → `'Check-up'`;
  6. `falls back to the other language label for en`: en with `receiptLabelEn:null` → `'ክትትል'`;
  7. `falls back to the full service name when no label exists`: both locales with both labels null →
     `'Long name'`.

### Browser-level specs (Playwright, for parity — `e2e/`)
- `patient-sale.spec.ts` → `reception creates a patient and records a sale`: RECEPTION login, create patient,
  `/sales/new` → click `Walk-in` → type into placeholder `Type a service, press Enter to add` → pick first option
  → click `Confirm sale` → URL matches `/\/sales\/[^/]+$/` → text `/receipt/i` visible.
- `roles.spec.ts` → `owner sees the revenue link` (link `Revenue` visible); `reception has no revenue link and is
  redirected from /revenue` (count 0 + forbidden page); `staff cannot open patient records`.

## 10. UI behavior per page

**New sale (`/sales/new`, `NewSaleForm`)**
- Hidden inputs submitted: `patientId`, `walkIn` (`'true'`/`'false'`), `doctorId`, `lines` =
  `JSON.stringify(lines.map(({serviceId,qty})=>({serviceId,qty})))`; visible `paymentMethod` select (default
  `CASH`, options = `PAYMENT_METHODS` labelled via `sales.pay*`).
- **Patient**: `Walk-in` toggle button (switches styling `btn-primary`/`btn-secondary`, clears patient + query +
  results; when on shows `walkInHint` and no patient field). Otherwise a search input
  (`patientSearchPlaceholder`) with **250 ms debounce**, empty/`<2` chars → results cleared, results limited by
  server (≤8, active patients, name `contains insensitive` OR phone-variant contains, `fullName asc`); picking a
  result shows name + E.164 with a `common.clear` button; typing again clears the selection (so a stale id is
  never submitted).
- **Services**: free-text filter over the passed **active** list, case-insensitive match on `nameEn`/`name`/
  `nameAm`, first 6 suggestions; `Enter` adds the first suggestion; suggestion row shows `nameEn || name` +
  `formatMoney(price)`. Adding a service already in the list **increments its qty by 1 (capped at 999)** —
  duplicate services merge into one row. Qty input clamps `1..999` (`Math.max(1, Math.min(999, Number||1))`);
  per-row `common.delete` removes it; empty state `sales.noLines`. Display name: `am` locale → `nameAm` if set,
  else `nameEn || name`. Client-side running total = `sumLineTotals` → `formatMoney`.
- Aside: doctor `<select>` (first option `sales.noDoctor` value `''`, options from `listDoctorOptions`), payment
  select, total, `common`-translated global `ActionError`, submit `sales.confirmSale` (pending: `common.saving`;
  disabled while pending or `lines.length===0`), hint `sales.totalsHint`. Field errors render
  `validation.<key>` under the relevant control (`patientId`, `lines`, `paymentMethod`).
- Success → `router.push('/sales/{id}?receipt=1')` + refresh. Prefill seeds patient (immediately selected),
  doctor select and a first line (qty 1, price from the service list) when `?serviceId` resolves.
- Patient type-ahead API: `searchPatientsAction(query)` returns `{id, fullName, phoneE164}[]`
  (`q` trimmed + sliced to 100; `<2` chars → `[]`).

**Sales list (`/sales`)**
- Header: `sales.title`, `New sale` button; OWNER only: `Void requests` link + filter card + `ExportLinks`
  (`/api/sales/export?…&format=csv|xlsx`, built with `page=1`).
- Table columns: receipt number (link → detail), date (`formatInTz` medium+short), patient (or `Walk-in`),
  doctor (or `—`), payment label, status pill (CONFIRMED emerald / VOIDED red / DRAFT amber), total
  (`formatMoney`). Empty: `common.noResults` when any filter set, else `common.empty`.
- Footer: `common.showing` `from–to of total`; OWNER additionally `sales.sumTotal` (`Total {formatted}`); prev/next
  links preserve the scoped query, disabled state = `btn-secondary opacity-50`; `Page {n} of {m}`.
- RECEPTION: no filter card, no export links, no sum, no voids button; rows forced to own sales of today.

**Sale detail (`/sales/[id]`)**
- Header: receipt number + status pill; actions: `common.back`, `PrintButtons`.
- **Receipt** (`ReceiptView`, `#receipt`, `data-print-mode="a5"`): clinic name (settings `clinic_name`) +
  `sales.receiptTitle`; rows: receipt number, date (`formatInTz`, clinic tz), patient (or `Walk-in`), doctor if
  present, payment label; line table uses `receiptLabelIn(locale, {receiptLabelEn/Am, fallback:
  serviceNameSnapshot})` + qty + `formatMoney(lineTotal, settings currency)`; total row; if `VOIDED` a red
  `sales.voidedWatermark` banner; footer `sales.receiptFooter`.
- **PrintButtons**: `sales.printA5` / `sales.printThermal`; injects a `<style>` at print time —
  thermal: `@page { size: 80mm auto; margin: 3mm; }`, A5: `@page { size: A5; margin: 10mm; }` — sets
  `root.dataset.printMode`, calls `window.print()`; the button row has class `no-print`.
- Audit trail card: `sales.auditTrail`; each row = translated label (map §5, else raw action) + `userName ·
  formatInTz(createdAt)`; empty → `common.empty`.
- Actions card (`common.actions`) with `ReasonActionButton` modal (reason textarea `required minlength=3
  maxlength=300`, label/cancel/saving states, errors translated from `sales.*` for
  `notConfirmed|alreadyRequested|noRequest`, `common.*` for `forbidden|unknownError|validationError`):
  - OWNER, CONFIRMED, **no** pending request → `Void sale` (danger; title `voidSaleTitle`, body `voidSaleBody`;
    on success redirect `/sales`).
  - RECEPTION (non-OWNER), CONFIRMED, no pending → `Request void` (secondary; title/body `requestVoidTitle`/
    `requestVoidBody`; on success just refresh).
  - OWNER + pending → hint `approveHint` + `Approve` (primary, **no reason**, success → `/sales/voids`) and
    `Reject` (danger, reason required, refresh).
  - Non-CONFIRMED and no pending → `sales.noActions`.
- Side cards: pending → amber card `voidRequestPending` + reason + `voidRequestedBy {name}`; VOIDED → red card
  `voidedWatermark` + reason + `voidedMeta {name}·{date}`; footer card `createdBy`/`createdAt`.

**Void inbox (`/sales/voids`, OWNER)**
- `sales.voidRequests` + back; table: receipt (link), date (medium), patient or `Walk-in`, reason (truncated by
  `max-w-xs`), requested by, total, actions = `Approve` (no reason) + `Reject` (reason) per row; rows ordered by
  `updatedAt asc` (oldest first); empty → `common.empty`.

**Revenue dashboard (`/revenue`, OWNER)**
- Preset pill row `Today / This week / This month / Last month / Custom` (`period*` keys; active preset =
  `btn-primary`); `custom` shows a GET form with two `type=date` inputs + `common.filter`.
- 4 cards: `Total revenue` + `vs previous period {change}` where change renders `+N%` / `-N%` (1 dp) or `—` when
  `percentChange` is null; `Number of sales`; `Average sale`; `Voided total` (red) + `{count} voided`.
- `Daily trend` card: one row per day (zero-filled): date key, horizontal bar width `total/trendMax*100%`
  (`trendMax = max(1, …)`), `min-width: 2px` only when `count > 0`, then `formatMoney(total) (count)`.
- 2×2 breakdown cards `byService/byCategory/byDoctor/byPaymentMethod`: table Name / Number of sales / Total;
  empty → `common.empty`; category labels via `services.cat*`, payment labels via `sales.pay*`, others raw label
  or `—`.

**Services list (`/dashboard/services`, OWNER)**
- `services.title` + `New service`; filter card: `common.search` input (`services.searchPlaceholder`), category
  select (fixed 10 keys + `common.all`), status select (`common.all`/`active`/`inactive`), Filter + Clear.
- Rows: name (+ `nameAm` subline), category label (raw key if unknown), `formatMoney(currentPrice)`, status pill
  (active emerald `common.active` / inactive slate `common.inactive`), actions: `common.edit` link +
  `Price history` modal. Empty → `common.noResults` (filtered) or `common.empty`.

**Service form (`…/new`, `…/[id]/edit`, OWNER)**
- Fields in order: `nameEn` (required, hint `nameEnHint`), `nameAm` (hint), grid `category` (placeholder
  `chooseCategory`, required) + `currentPrice` (text, `inputMode=decimal`, placeholder `0.00`, hint `priceHint`),
  edit-only `priceChangeNote` ("Changing the price stores it in history and only affects future sales."),
  Receipt fieldset (`receiptLabelEn/Am` + `receiptLabelsHint`), `displayOrder` (number ≥0),
  Public fieldset: `isPublic` checkbox (**default true on create**), `imageUrl` (text + existing preview img),
  `image` file (`accept="image/png,image/jpeg,image/webp"`), `publicDescriptionEn` textarea (maxLength 2000),
  `publicDescriptionAm` textarea (controlled; when `isPublic` and blank shows amber badge `services.toBeProvided`).
- Submit: pending → `common.saving`; create → `services.create`, edit → `common.save`; cancel link back to
  `/dashboard/services`. Success → push `/dashboard/services`. Global `ActionError` + per-field
  `validation.*` messages.
- `ServiceStatusButton` (below the edit form): hidden `id` + `active` = inverted current value; `window.confirm`
  with `services.deactivateConfirm` / `reactivateConfirm` (prevents submit on cancel); hint text
  `activeHint`/`inactiveHint`; button `Deactivate`/`Reactivate` (`btn-danger`).
- `PriceHistoryModal`: button `services.priceHistory` → opens overlay (`role=dialog`, backdrop closes) →
  `getPriceHistoryAction(serviceId)` → `common.loading` → empty `priceHistoryEmpty` or table Old price / New
  price / Changed by / Changed at (money + `formatInTz`).

**Patient detail "Sales" tab** (`PatientSalesList`): `patientNoSales` when empty; else
`patientSalesCount {count}` + `patientTotal {total}` (CONFIRMED sum only), table receipt / date (medium) /
status pill (`CONFIRMED` emerald, `DRAFT` amber, `VOIDED` red) / total / `sales.view` link to `/sales/{id}`.
