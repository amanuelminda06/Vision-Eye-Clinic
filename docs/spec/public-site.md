# Public website — Laravel rebuild spec

Legacy: Next.js 16 app-router ERP (`erp/`). Scope: public marketing site (route group `(public)`), CMS-lite site content, public JSON APIs, feedback moderation. Source of truth files: `src/modules/public-site/**`, `src/modules/feedback/**`, `src/app/(public)/**`, `src/app/api/public/**`, `src/app/sitemap.ts`, `src/app/robots.ts`, `src/proxy.ts`, `src/i18n/**`.

All strings below are exact legacy values. Currency/phone never leak; "rule N" cross-references §6.

---

## 1. Routes & pages

### 1.1 Public pages (no session; served to everyone)

| Legacy URL | Laravel path (same) | Purpose | Renderer notes |
|---|---|---|---|
| `/` | `GET /` | Home: hero, featured services, doctors, announcements, testimonials, CTA | Per-request DB render (embeds "now": announcement window, on-duty doctors) |
| `/about` | `GET /about` | About: i18n body + 3 "why us" cards | Static content from i18n messages |
| `/services` | `GET /services` | Public service catalogue grouped by category; **no prices** | DB per request |
| `/doctors` | `GET /doctors` | Published doctors + duty badges + today's shifts | DB per request |
| `/book` | `GET /book` | Booking form (client JS posts to API) | DB per request (services list, clinic tz for `minDate`) |
| `/contact` | `GET /contact` | Contact card, hours, feedback form, Google Maps embed | DB per request |
| `/faq` | `GET /faq` | FAQ accordion from CMS JSON | DB per request |
| `/privacy` | `GET /privacy` | Privacy policy body from CMS | DB per request |
| `/opengraph-image` | `GET /opengraph-image` (or pre-rendered PNG) | 1200×630 PNG OG image, generated locally, **locale-invariant** (cacheable across locales) | See §8 |
| `/sitemap.xml` | `GET /sitemap.xml` | Sitemap, 8 URLs | §8 |
| `/robots.txt` | `GET /robots.txt` | Crawler rules | §8 |

### 1.2 Bounce / 404 rules (port of `src/proxy.ts` gate)

- Public set is EXACTLY: `/`, `/about`, `/services`, `/doctors`, `/book`, `/contact`, `/faq`, `/privacy`; plus paths starting `/opengraph-image` or `/twitter-image`. Trailing slash on any path is stripped before matching.
- `?lang=en|am` on public paths: valid value → set `locale` cookie (`path=/`, `maxAge=31536000`, `samesite=lax`) and honour for rendering; invalid/absent → ignore. No URL locale prefix exists; language is cookie-based.
- `/login/<LOGIN_SECRET_PATH>` (env var, secret): the ONLY auth entry. `/login` or `/login/*` anything else → hard `404` (null body, scanners learn nothing).
- Everything else (all `/api/*`, ERP screens, `/settings`, …): request must carry a session cookie (`authjs.session-token` or `__Secure-authjs.session-token` — optimistic sniff only; real check in `requireRole()`); otherwise redirect (query string dropped) to `/login/<LOGIN_SECRET_PATH>`.
- The middleware matcher skips `/api` entirely — so **`/api/public/*` routes are reachable without a session**; internal `/api/*` routes are protected by their own `requireRole()` calls.
- Unknown public URL inside the public group → `not-found.tsx`: big orange `404`, `site.notFoundTitle`, `site.notFoundBody`, button `site.backHome` → `/`.
- All public pages are dynamic (no static shell): they embed current-time state.

### 1.3 Public API endpoints

`GET  /api/public/doctors` · `GET  /api/public/doctors/{id}/photo` · `POST /api/public/requests` · `POST /api/public/feedback` · `GET  /api/public/services/{id}/image` — full contracts in §4.

---

## 2. Content model

DB table `SiteContent(key PK, valueEn, valueAm, updatedBy, updatedAt)` — one row per key, bilingual columns. Rendered value = DB row merged over `DEFAULT_CONTENT` placeholders (§2.2). Values are plain strings; three keys hold JSON arrays (§2.3).

### 2.1 `CONTENT_KEYS` (complete, order as in code)

| Key | What it is | Default `en` | Default `am` |
|---|---|---|---|
| `hero_title` | H1 hero headline (home) | `Clear vision for every stage of life` | `ለሁሉም የህይወት ደረጃ ግልጽ እይታ` |
| `hero_subtitle` | Hero paragraph | `Comprehensive eye care for your whole family — from routine exams to specialist treatment.` | (Amharic translation) |
| `hero_promise` | Hero pill/badge line | `Trusted, patient-first eye care in Addis Ababa.` | (Amharic) |
| `home_intro` | Home intro paragraph | `Our experienced ophthalmologists and optometrists provide thorough eye examinations…` | (Amharic) |
| `footer_note` | Small disclaimer under footer About | `This website is for general information only and does not replace a medical consultation.` | (Amharic) |
| `privacy_body` | Privacy page body; paragraphs split on blank lines | `PLACEHOLDER — This privacy policy has not been finalised…` (explicit placeholder) | (Amharic placeholder) |
| `announcement_text` | Announcement banner text (single line) | `''` | `''` |
| `announcement_active` | Banner on/off flag, string `'true'`/`'false'` | `'true'` | `'true'` |
| `announcement_start` | Inclusive start date `YYYY-MM-DD` of banner window; `''` = open | `''` | `''` |
| `announcement_end` | Inclusive end date; `''` = open | `''` | `''` |
| `announcements_items` | JSON array `{title,message}` — announcements SECTION on home | `[{"title":"Free vision screening this Saturday","message":"Bring your family for a quick eye check. No appointment needed."}]` | 1 placeholder item |
| `faq_items` | JSON array `{question,answer}` — FAQ page | 3 items (appointment/what to bring/painful?) | 3 Amharic items |
| `testimonials_items` | JSON array `{name,text}` — manual testimonials | 2 × `Sample patient` / `Placeholder testimonial — replace with a real, consented patient review.` | 2 Amharic |
| `contact_address` | Address string | `Kaliti Farid Building, 2nd floor, Addis Ababa, Ethiopia` | `''` |
| `contact_phone_primary` | Primary phone; ALSO source for WhatsApp + Telegram links | `+251900000000` | same |
| `contact_phone_backup` | Backup phone, shown as text | `''` | `''` |
| `contact_map_url` | "Get directions" link in contact Address card | `https://maps.app.goo.gl/Cyye5QKD3qjUWVXA6` | same |
| `social_tiktok` | TikTok profile URL (footer icon) | `https://tiktok.com/@visioneyeclinic` | same |
| `social_instagram` | Instagram URL (footer icon) | `https://instagram.com/visioneyeclinic` | same |
| `social_telegram` | Telegram URL (footer icon — NOT the chat button) | `https://t.me/visioneyeclinic` | same |
| `doctors_intro` | Doctors page intro paragraph (falls back to i18n `site.doctors.intro` when blank) | `Meet the team behind your bright view — …` | `PLACEHOLDER — Amharic doctors intro pending native-speaker review.` |
| `city` | JSON-LD `addressLocality` | `Addis Ababa` | placeholder |
| `country` | JSON-LD `addressCountry` | `Ethiopia` | placeholder |
| `specialty` | JSON-LD `medicalSpecialty` | `Ophthalmology` | placeholder |
| `og_title` | (Stored, editable, but OG image is currently hardcoded — see §8) | `Clear vision for every stage of life` | placeholder |
| `og_subtitle` | (same caveat) | `Eye examinations · Glasses · Diagnostics · Specialist care` | placeholder |
| `og_city` | (same caveat) | `Addis Ababa, Ethiopia` | placeholder |
| `map_lat` | Map latitude (string) | `8.896235398615133` | same |
| `map_lng` | Map longitude | `38.77100409325467` | same |
| `map_zoom` | Map zoom (string; parsed to int; clamped to 1–21, default 15 if blank) | `15` | same |

### 2.2 Merge & locale rules

- `resolveContent(rows)`: start from `DEFAULT_CONTENT`; for each row present, `map[key] = { en: row.valueEn, am: row.valueAm || row.valueEn }` — **blank Amharic at the DB level is backfilled with English at resolve time**.
- `pick(locale, value)`: `am` → `value.am || value.en`; anything else → `value.en`.
- Announce-window flag values are truthy only for the exact strings `'true'`, `'1'`, `'yes'`.

### 2.3 JSON item keys (stored as JSON strings in one text cell per locale)

- `faq_items`: array items must be objects with string `question` AND string `answer`; anything else filtered out.
- `testimonials_items`: string `name` + string `text`.
- `announcements_items`: string `title` + string `message`.
- Parsing is defensive (`parseItems`): invalid JSON → `[]`; non-array → `[]`; invalid members dropped. Never throws.

### 2.4 CMS edit rules (`saveSiteContentAction`, OWNER only — §5)

- Form posts FLAT fields `<key>_en` and `<key>_am` for every key; each value trimmed; missing → `''`.
- Special key `announcement_active`: posted as a single checkbox named `announcement_active` value `'true'`; saved as `{ en: 'true' | 'false', am: '' }` (checkbox mirrored into the `en` column only).
- `announcement_start`/`announcement_end` posted as `type=date` inputs (the `_am` halves are hidden inputs echoing current `am` value).
- `announcements_items_en/_am`, `faq_items_en/_am`, `testimonials_items_en/_am` are hidden inputs containing `JSON.stringify` of the edited arrays.
- Every one of the 30 keys is upserted in ONE transaction (update sets `valueEn,valueAm,updatedBy`; create adds `key`). Value max length enforced by schema: 5000 chars per field (`contentEditSchema`: record of string→string `.max(5000,'tooLong')`).
- Audit: one `SITE_CONTENT_UPDATED` entry, entity `SiteContent`, entityId `'all'`, with per-key `{en,am}` diff (old = resolved-before map, new = submitted map).
- Amharic values are labelled as drafts (`content.am` = `Amharic (draft — needs review)`); editor shows an "Amharic" collapsible per JSON list.

---

## 3. Pure logic to port

### 3.1 Public projection allow-lists + leak scanner (`projection.ts`)

`FORBIDDEN_PUBLIC_FIELDS` (must NEVER appear in any public payload, checked recursively incl. arrays):
`phone, phoneE164, email, notes, notesNonClinical, price, currentPrice, cost, salary, passwordHash, annualLeaveDays, reason, leaveReason, decisionNote, ipHash, patientId, staffId, userId`.

Allow-lists (a projected object's key set must equal the sorted allow-list):

- `PUBLIC_SERVICE_FIELDS` = `id, name, nameEn, nameAm, category, displayOrder, imageUrl, descriptionEn, descriptionAm`. Mapper `toPublicService(row)` copies `row.publicDescriptionEn → descriptionEn`, `row.publicDescriptionAm → descriptionAm`; **price never selected nor copied**.
- `PUBLIC_DOCTOR_FIELDS` = `id, name, specialty, photoUrl, status, todayShifts, nextShiftStartsAt` (produced by `buildPublicDoctorStatus`, §3.6).
- `PUBLIC_FEEDBACK_FIELDS` = `id, name, text, rating` (mapper `toPublicFeedback`); email/ipHash/showOnWebsite/createdBy dropped.
- `findForbiddenFields(value)`: recursive walk (arrays + objects); returns the SET of forbidden key names found anywhere. Used by tests to scan serialized payloads — Laravel: a test helper doing `array_dot`-style recursive key collection over the JSON-decoded response.

### 3.2 Format helpers (`format.ts`)

- `telHref(phone)`: strip everything except digits and `+`; if ≥6 digits remain → `tel:<cleaned>` else `null` (also null for empty input).
- `whatsappHref(raw)`: if starts `https?://` (case-insensitive) → return as-is; else digits-only → `https://wa.me/<digits>`; no digits → `null`.
- `telegramHref(raw)`: if `https?://` → as-is; else strip leading `@`, keep only `[A-Za-z0-9_]` → `https://t.me/<handle>`; empty → `null`.
- `formatNextAvailable(iso, timeZone, locale)`: `Intl.DateTimeFormat` with `weekday:'short', hour:'2-digit', minute:'2-digit'`, locale `am-ET` when `locale==='am'` else `en-GB`, clinic tz (fallback `Africa/Addis_Ababa`); on error → `''`. Example output: `Mon 14:30`. Laravel: `Carbon::isoFormat('ddd HH:mm')` with locale `am`→`am_ET`.
- `workingHoursLines(hours, label, closedLabel)`: iterate days in fixed order `mon,tue,wed,thu,fri,sat,sun`; each line `{day: label(day), value: closed ? closedLabel : "{open}–{close}"}` (en-dash between times).
- `publicServiceImageUrl(serviceId, imageUrl)`: `null`→`null`; startsWith `/api/uploads/` → `/api/public/services/{serviceId}/image`; otherwise return as-is (site-relative static path).

### 3.3 SEO (`seo.ts`)

- `siteBaseUrl()`: `PUBLIC_BASE_URL` env trimmed of trailing `/`, else literal `http://localhost:3000`.
- `pageMetadata({title, description, path, isHome})`:
  - `title`: home → `{absolute: title}`; others → plain `title` (Next template appends suffix — in Laravel set full titles per §8).
  - `canonical`: the SITE-RELATIVE `path` (no query, no base).
  - hreflang alternates: `en → "{path}?lang=en"`, `am → "{path}?lang=am"`, `x-default → "{path}"`.
  - OpenGraph: `type:'website'`, `siteName:'Vision Eye Clinic'` (page level; layout level uses profile name), `title`, `description`, absolute `url = base + path`, `locale:'en_ET'`, `alternateLocale:['am_ET']`.
  - Twitter: `card:'summary_large_image'`, `title`, `description`.
  - OG image comes from the `opengraph-image` file convention (not in metadata object).
- Layout adds `metadataBase = base`, `applicationName = clinic name`, `openGraph.siteName = clinic name`.

### 3.4 Booking schema (`bookingFormSchema`, zod → Laravel FormRequest)

Fields (server receives JSON, not form-encoded):

| Field | Rule(s) | Error key(s) |
|---|---|---|
| `name` | trim, required, max 100 (`MAX_REQUEST_NAME`) | `required`, `tooLong` |
| `phone` | trim, required, max 32 (format checked later, §4.2) | `required`, `tooLong`, (`invalidPhone`/`required` from phone parser) |
| `preferredDate` | regex `^\d{4}-\d{2}-\d{2}$` | `invalidDate` |
| `preferredTimeWindow` | enum `any\|morning\|afternoon\|evening`, default `any` | `invalidTimeWindow` |
| `serviceInterest` | trim, optional, max 200, `''`→`null` | `tooLong` |
| `message` | trim, optional, max 500, `''`→`null` | `tooLong` |
| `language` | enum `en\|am`, default `en` | `invalidLanguage` |
| `consent` | `true`/`'true'` accepted; must be true | `consentRequired` |
| `website` | HONEYPOT: optional, default `''`, max length 0 | `spam` |
| `turnstileToken` | optional, max 4096 | `tooLong` |

- Validation failure contract: `ValidationError` → field map `Record<fieldPath, errorKey>` (first issue per field wins; key = dot-joined path or `_`). HTTP shape in §4.2.
- `isHoneypotTripped(values)` = `values.website.trim().length > 0`.
- 400/422 contract, booking route order (exact): ① rate limit 429; ② JSON parse fail → `400 {ok:false,error:'invalidJson'}`; ③ schema validate → `422 {ok:false,error:'validationError',fields:{…}}`; ④ honeypot check (unreachable when filled because schema already rejects it with `fields.website='spam'`); ⑤ turnstile → `422 fields={turnstileToken:'required'}`; ⑥ phone parse → `422 fields={phone:'required'|'invalidPhone'}`; ⑦ business errors: `invalidDate`/`pastSlot` → `422 fields={preferredDate: <error>}`; `noDoctorAvailable` → `503 {ok:false,error:'noDoctorAvailable'}`; other → `500 {ok:false,error: <error>}`; ⑧ success → `200 {ok:true,id,appointment}` + `Cache-Control: no-store`.
- ⚠️ Divergence preserved: on the FEEDBACK route the honeypot is checked on the RAW body BEFORE schema (silent drop, §4.3); on the BOOKING route schema runs first, so a filled honeypot actually yields `422 fields={website:'spam'}`. Rebuild exactly or consciously unify (documented as resolved ambiguity, §11).

### 3.5 Feedback schema (`feedbackFormSchema`)

| Field | Rule(s) | Error key |
|---|---|---|
| `name` | trim, required, max 120 (`MAX_FEEDBACK_NAME`) | `required`, `tooLong` |
| `email` | trim, lowercase, optional, max 254, `''`→`null`, must match `EMAIL_RE` when present | `tooLong`, `invalidEmail` |
| `rating` | number-or-string → number; `''`/absent → `null`; integer 1..5 | `invalidRating` |
| `message` | trim, required, max 1000 (`MAX_FEEDBACK_TEXT`) | `required`, `tooLong` |
| `language`,`consent`,`website`,`turnstileToken` | same as booking | same keys |

### 3.6 Doctor public status (`buildPublicDoctorStatus`, hr module)

- Input staff restricted to `{id, fullName, specialty, photoUrl, isDoctor, showOnWebsite, active}`; `isPublicDoctor` = all three flags true.
- Status priority: APPROVED leave covering today (clinic-local date) → `ON_LEAVE`; else a shift containing `now` → `ON_DUTY_NOW`; else `NOT_ON_DUTY`.
- `todayShifts`: shifts overlapping `[startOfDay(now), endOfDay(now))` (clinic tz), sorted by start, rendered as `{start:'HH:mm', end:'HH:mm'}` clinic-local.
- `nextShiftStartsAt`: currently-running shift's start if on duty, else next shift start after `now`; ISO instant string or `null`.
- List query (`getPublicDoctorStatus`): staff where `active && isDoctor && showOnWebsite`, `orderBy fullName asc`; shifts fetched for those ids with `endsAt >= startOfDay`; results sorted `ON_DUTY_NOW`(0) → `ON_LEAVE`(1) → `NOT_ON_DUTY`(2), then name A→Z.
- Timezone comes from clinic settings (`Africa/Addis_Ababa` default). `clinicDateKey(instant, tz)` = local `YYYY-MM-DD`; `combineDateAndTime(dateKey,'HH:mm',tz)` = wall clock → UTC instant (offset-aware).

### 3.7 Turnstile (`turnstile.ts`) — behaviour when disabled

- `turnstileEnabled()` ⇔ `TURNSTILE_SECRET_KEY` env set (optional var, default unset).
- `verifyTurnstile(token, remoteIp)`: **no secret configured → always `true` (no-op)** — booking works with honeypot + rate limit alone. With secret: missing token → `false`; else POST `https://challenges.cloudflare.com/turnstile/v0/siteverify` with `secret`, `response`, optional `remoteip`; `success === true` only; any exception → `false`.
- Client renders the `.cf-turnstile` widget only when `NEXT_PUBLIC_TURNSTILE_SITE_KEY` is set; CSP must allow `challenges.cloudflare.com` when enabled.

### 3.8 Announcement active-window (`isAnnouncementVisible(content, now, tz, dateKeyFn)`)

1. `truthy(announcement_active.en)` (`'true'|'1'|'yes'`) else hidden.
2. Both `announcement_text.en.trim()` and `.am.trim()` empty → hidden.
3. `today = clinicDateKey(now, tz)`; if `start` non-empty and `today < start` → hidden; if `end` non-empty and `today > end` → hidden. Window is INCLUSIVE on both ends, clinic-local dates, string comparison (ISO keys).

### 3.9 Misc pure helpers

- `parsePhone` (§4.2 phone grammar), `hashClientIp` = SHA-256 hex of raw IP string; empty input → `''` (never store raw IP — rule 8).
- Rate limiter: in-memory sliding window, per key; when `count >= max` block; bucket reset when expired; sweep when >5000 buckets. Laravel: cache-based limiter with same numbers.
- Time windows (`TIME_WINDOWS` + `slotForTimeWindow`): `any`→`09:00–10:00`, `morning`→`09:00–10:00`, `afternoon`→`14:00–15:00`, `evening`→`16:00–17:00` (clinic wall clock; unknown key falls back to `any`). Appointment duration `DEFAULT_DURATION = 30` minutes. Bookable horizon `MAX_REQUEST_DATE_DAYS_AHEAD = 180` days.

---

## 4. Public APIs

Common: JSON in/out; excluded from the session gate; no auth; errors `{ok:false, error:<code>, fields?}`.

### 4.1 `GET /api/public/doctors`

- Auth: none. Rate limit: none (cached instead).
- Response `200`:
  ```json
  { "doctors": [ { "id","name","specialty","photoUrl","status","todayShifts":[{"start","end"}],"nextShiftStartsAt" } ], "generatedAt": "<ISO instant>" }
  ```
  `status ∈ ON_DUTY_NOW|ON_LEAVE|NOT_ON_DUTY`; doctors sorted duty-rank then name (§3.6). Only `showOnWebsite && isDoctor && active`.
- Headers: `Cache-Control: public, max-age=60, stale-while-revalidate=120`.
- Only `PUBLIC_DOCTOR_FIELDS` may appear (rule 5).

### 4.2 `POST /api/public/requests` (booking)

- Auth: none. Rate limit: key `public-booking:{ip|'unknown'}`, **max 5 / 3600 000 ms (1 h)** per IP (`PUBLIC_BOOKING_RATE_MAX=5`, window 60 min). IP = first hop of `x-forwarded-for`, trimmed.
- Body: JSON matching §3.4.
- Status codes / bodies:
  - `429 {ok:false, error:'rateLimited'}`
  - `400 {ok:false, error:'invalidJson'}`
  - `422 {ok:false, error:'validationError', fields:{field:errorKey}}` (schema / turnstile `{turnstileToken:'required'}` / phone `{phone:'required'|'invalidPhone'}` / booking `{preferredDate:'invalidDate'|'pastSlot'}`)
  - `500 {ok:false, error:'unknownError'|…}`
  - `200 {ok:true, id:<requestId>}` — header `Cache-Control: no-store`.
- Behaviour (the request queue is restored — resolved ambiguity §11.1): phone normalized to E.164 (`09/07XXXXXXXX`, `9/7XXXXXXXX`, `2519/7XXXXXXXX`, `+2519/7XXXXXXXX`; spaces/dashes/dots/parens tolerated; else `invalidPhone`). Date must be within `[today, today+180d]` clinic-local, and the slot start must be in the future (`pastSlot`). The submission creates ONE `AppointmentRequest` row `{name, phoneE164, preferredDate, preferredTimeWindow, serviceInterest, message, language, status:'NEW', patientId?, ipHash}` — **never** an appointment and **never** a patient. An existing patient with the same `phoneE164` is LINKED (`patientId`); nothing is created. No doctor is assigned at intake. Audit `REQUEST_CREATED` (user null, ip null) with `{status, preferredDate, preferredTimeWindow, serviceInterest, language, patientId, ipHash}`. Only `hashClientIp(ip)` persisted — never the raw IP (rule 8). The front desk converts the request later (see `docs/spec/appointments.md` §6.11).

### 4.3 `POST /api/public/feedback`

- Auth: none. Rate limit: key `public-feedback:{ip|'unknown'}`, **max 3 / hour** (`PUBLIC_FEEDBACK_RATE_MAX=3`).
- Order: ① 429 `rateLimited`; ② 400 `invalidJson`; ③ **honeypot on RAW body**: non-empty string `website` → `200 {ok:true, id:null}` + `Cache-Control: no-store` (silent accept-and-drop — bot must not learn it was caught); ④ schema → 422 `validationError` + fields; ⑤ turnstile → 422 `{turnstileToken:'required'}`; ⑥ create → ⑦ `500 {ok:false,error:'unknownError'}` on failure; ⑧ `200 {ok:true,id}` + `Cache-Control: no-store`.
- Persistence (`createPublicFeedback`): row `{name, email, rating, text=message, language, ipHash, showOnWebsite: false, createdBy: 'public-website'}` — starts HIDDEN; publish only via OWNER toggle (§5). Audit `FEEDBACK_SUBMITTED` (user null, ip null) `{source:'WEBSITE', rating, language, ipHash}`.

### 4.4 `GET /api/public/doctors/{id}/photo`

- Auth: none. Lookup staff `{id, isDoctor:true, showOnWebsite:true, active:true}` select `photoUrl` only. If no row, no `photoUrl`, or `photoUrl` does not start `/api/uploads/` → `404` empty body. Storage key = prefix-stripped path; missing bytes → `404`.
- Success: raw bytes; `Content-Type` by extension map `png→image/png, jpg/jpeg→image/jpeg, webp→image/webp, gif→image/gif`, else `application/octet-stream`; `Cache-Control: public, max-age=3600, stale-while-revalidate=86400`.

### 4.5 `GET /api/public/services/{id}/image`

- Identical mechanics; lookup service `{id, isPublic:true, active:true}`, `imageUrl` starting `/api/uploads/`; same content-type map and `Cache-Control: public, max-age=3600, stale-while-revalidate=86400`; else `404`.
- Static site-relative image paths (`/services/…png`) bypass this route entirely (rule 10).

### 4.6 Supporting data functions (server-side, not HTTP)

- `getPublicServices()`: services `where isPublic && active`, select only `PUBLIC_SERVICE_FIELDS` source columns (never price), order `displayOrder asc, category asc, name asc`.
- `getPublicFeedbackTestimonials()`: `where showOnWebsite`, select `id,name,text,rating`, `orderBy createdAt desc`, `take 12`.
- `getSiteContent()`: all rows → `resolveContent`.
- `getClinicProfile()`: `{name: settings.clinic_name, timezone, workingHours, reviewLink, defaultLanguage}` from clinic settings (never prices/patients).

---

## 5. Role matrix for CMS actions

Roles: `OWNER`, `RECEPTION`, `STAFF`. Public site actions:

| Action | Endpoint/function | Allowed roles | Success | Failure | Audit action string (exact) | Audit entity / id |
|---|---|---|---|---|---|---|
| Save website content | `saveSiteContentAction` | **OWNER only** (`CONTENT_ROLES=['OWNER']`) | `ok()` | non-OWNER → forbidden; invalid field → validation error | `SITE_CONTENT_UPDATED` | `SiteContent` / `'all'` |
| Publish/unpublish testimonial | `setFeedbackWebsiteAction` | **OWNER only** (`FEEDBACK_ROLES=['OWNER']`) | `ok()`; no-op `ok()` when value unchanged | unknown id → `fail('unknownError')` | `FEEDBACK_WEBSITE_CHANGED` (old/new `{showOnWebsite}`) | `Feedback` / id |
| Delete feedback | `deleteFeedbackAction` | **OWNER only** | `ok()` | unknown id → `fail('unknownError')` | `FEEDBACK_DELETED` (old `{name, showOnWebsite}`) | `Feedback` / id |
| Public feedback submit | `createPublicFeedback` | anonymous (no session) | `{ok:true,id}` | `{ok:false,error:'unknownError'}` | `FEEDBACK_SUBMITTED` (ip:null; new `{source:'WEBSITE',rating,language,ipHash}`) | `Feedback` / id |
| Public booking | `createPublicBooking` | anonymous | `{ok:true,id,appointment}` | see §4.2 | `APPOINTMENT_CREATED` (ip:null; new `{source:'WEBSITE',status:'CONFIRMED',doctorId,startsAt,endsAt,serviceId,ipHash[,assignedDespiteClash]}`) | `Appointment` / id |

CMS feedback list (OWNER-only screen): `listFeedback({q, website})` — `website` filter `''|'true'|'false'` maps to `showOnWebsite`; `q` = case-insensitive contains over `name`,`text`,`email`; newest first, `take 200` (`FEEDBACK_LIST_LIMIT`); the LIST may show `email` (reply address) to the OWNER but `ipHash` is never selected. Toggle posts FormData `id`, `showOnWebsite` (`'true'`|other). Website-content editor posts flat `<key>_en/_am` (§2.4).

---

## 6. Business rules & invariants (numbered)

1. **No price publicly, ever.** Services queries never `SELECT` price columns; services page/cards show no price (intro explicitly says so); projections exclude `price/currentPrice/cost/salary`.
2. **No patient data publicly.** No public response may contain `patientId/staffId/userId/notes/notesNonClinical/email/phone/phoneE164/ipHash/passwordHash/annualLeaveDays/reason/leaveReason/decisionNote` (§3.1 FORBIDDEN list; recursive scan in tests).
3. Services page + home featured: only `isPublic && active` services.
4. Doctors page + home + API: only `isDoctor && showOnWebsite && active` doctors; duty state computed clinic-locally; APPROVED leave today overrides shift.
5. All public payloads are strict allow-list projections (§3.1) — extra DB columns must never survive a mapper.
6. Booking files a **new AppointmentRequest with status `NEW`** — NOT an appointment and NOT a patient. The front desk converts it (which creates the patient and a `SCHEDULED` appointment), contacts the visitor, declines it or marks it as spam. Audit `REQUEST_CREATED` (with `ipHash` only) marks the origin; `createdFrom:'WEBSITE_REQUEST'` marks the converted appointment. (The legacy queue is restored — see §11.1.)
7. Booking/feedback consent checkbox is REQUIRED (`consentRequired`) and must be true.
8. IP privacy: only SHA-256 `ipHash` persisted (audits carry `ip:null`); raw IP used only for in-request rate limiting.
9. Honeypot field `website` must be empty; hidden from humans + assistive tech (`aria-hidden`, `tabIndex -1`, `autocomplete off`). Feedback route drops silently (`200 {ok:true,id:null}`); booking route rejects via schema `422 spam` (§3.4 note).
10. Uploaded images (`/api/uploads/…`) are never exposed directly; only through the two narrow allow-listed routes gated by publication flags; static site-relative paths render as-is.
11. Rate limits: booking **5/h per IP**, feedback **3/h per IP**, key prefixes `public-booking:` / `public-feedback:`, key `'unknown'` when no IP.
12. Bilingual site: locale ∈ `en|am`; resolved per request from `?lang=` → `x-locale` header (proxy) → `locale` cookie → default `en`. `am` falls back to `en` per value, per key.
13. Turnstile OFF by default (no secret ⇒ verify passes); honeypot + rate limit always active.
14. Announcement banner obeys active flag + inclusive clinic-local date window (§3.8); banner text is `pick(locale, announcement_text)`.
15. `isAnnouncementVisible` uses only the `en` column of the flag/window keys (single global window, not per-locale).
16. Analytics is a **no-op** — no provider, no cookies, no third-party requests (component renders `null`). The ONE exception is the Google Maps embed (§ rule 17): it is a third-party frame, so Google sees the visit. Both maps are `loading="lazy"`, so the request only happens when the map scrolls into view.
17. Map is a **Google Maps `<iframe>`** (no API key, no third-party script): the keyless form `https://www.google.com/maps?q=<lat>,<lng>&z=<zoom>&output=embed`, built server-side by `App\Support\PublicSite\MapEmbed` from the CMS position (`map_lat`, `map_lng`, `map_zoom`); a blank position renders NO map. It appears on the **contact page** (256 px) and in the **footer** (112 px, lazy) of every public page. CSP `frame-src 'self' https://www.google.com`; CSP `img-src` has NO external source; CSP also sets `default-src 'self'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`; security headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, HSTS `max-age=63072000; includeSubDomains` (prod).
18. JSON-LD is escaped (`<` → `<`) when injected; no user input goes into it beyond CMS scalars.
19. Public pages never render staff contact details — only clinic phones from site content.
20. Feedback becomes a public testimonial ONLY after OWNER publishes it; public testimonials expose `id,name,text,rating` only.
21. Every public write marks `createdBy` (`'public-booking'` / `'public-website'`) — no user FK.
22. Booking phone must normalize to Ethiopian E.164 `+2519XXXXXXXX|+2517XXXXXXXX` or the request is rejected `invalidPhone`.
23. Public site renders per-request (dynamic); APIs set explicit `Cache-Control` (§4).
24. Booking date min = clinic-local today (date picker `min`); max = today + 180 days; slot must be in the future.

---

## 7. Page-by-page UI content

### 7.1 Global chrome

- **Header** (sticky): logo + clinic name (settings) + Amharic name `site.clinicNameAm`; nav links in order `navHome /`, `navAbout /about`, `navServices /services`, `navDoctors /doctors`, `navContact /contact`, `navFaq /faq` with `aria-current="page"` + orange underline on active (prefix match; `/` exact); locale switcher (`EN` / `አማ`, sets cookie + refresh, `aria-pressed`); "Call now" `tel:` button when phone present; `Book an appointment` button; mobile hamburger (`aria-expanded`, labels `site.menuLabel`/`site.menuClose`) revealing the same links + switcher + call + book.
- **Skip link** first focusable: `site.skipToContent` → `#main`.
- **Announcement banner** (top of `<main>`, id `announcements`, `role=region`, aria-label `Announcement`): single centred orange bar with `announcement_text`; hidden if blank.
- **Footer**: About blurb `site.footerAbout` + `footer_note` (CMS) + social icons (TikTok/Instagram/Telegram from CMS URLs, `target=_blank rel=noopener noreferrer`, sr-only labels); Quick links nav (`navServices, navDoctors, bookAppointment, navContact, navFaq, footerPrivacy`); Contact block (address, phone as `tel:`); opening hours snippet (always `hoursText=null` in layout — effectively omitted); mini-map (112 px, lazy) — a **Google Maps `<iframe>`** when `map_lat`+`map_lng` are set; bottom bar `site.footerRights` = `© {year} {clinic}. All rights reserved.`.
- **JSON-LD** (every public page): `@type:'MedicalClinic'`, `name`, `url: siteBaseUrl()`, `telephone` (when set), `address:{@type:'PostalAddress', streetAddress: address, addressLocality: city, addressCountry: country}` (when address), `medicalSpecialty: specialty`, `availableService: [{@type:'MedicalProcedure', name}]` per public service (am locale uses `nameAm || name`) — `openingHours` omitted (hoursText always null).
- **Analytics**: renders nothing.

### 7.2 Home `/`

1. Hero: blurred `/hero.jpg` backdrop, logo + clinic name + `clinicNameAm`, promise pill (`hero_promise`), H1 `hero_title`, sub `hero_subtitle`, buttons `bookAppointment` → `/book` + `callNow` (tel). Right card "Why patients trust us" (`home.whyTitle`) with 3 checkmark items `why1..3Title/Text`.
2. Services: `home.servicesTitle`, `home.servicesIntro`; **first 6** public services as cards (image via `publicServiceImageUrl`, category label raw, name locale-aware, description line-clamp-3); empty state = bordered box `services.empty`; link `home.viewAllServices →` `/services`.
3. Doctors strip: `home.doctorsTitle`, `home.doctorsIntro`; `onDuty = status==='ON_DUTY_NOW'`; show `onDuty.length ? onDuty : doctors`, slice 4; avatar 72 px (initials fallback, alt `doctors.photoAlt {name}`), name, specialty, `DoctorStatusBadge`; empty state `doctors.empty`; link `home.viewAllDoctors →` `/doctors`.
4. Announcements (only when items exist): `home.announcementsTitle` + cards `{title, message}`.
5. About recap band: `meta.aboutTitle` + `meta.aboutDescription` + why1..3 as cards.
6. Testimonials (only when list non-empty): `home.testimonialsTitle`, `home.testimonialsIntro`; merge approved feedback (name,text,rating) FIRST then manual CMS testimonials, **max 3**; stars amber/slate with aria `home.ratingAria {rating}{max}`; quote + name footer; when NO approved feedback shown, footnote `home.testimonialsPlaceholder`.
7. CTA band (orange): `home.ctaTitle`, `home.ctaText`, `bookAppointment` button + phone `tel:` button; card with `home.locationTitle` (address) and `home.hoursTitle` (7 working-hours lines from settings, day labels `settings.dayMon…daySun`, closed = `settings.closed`).

### 7.3 Services `/services`

H1 `services.title`, intro `services.intro` (mentions no online prices). Group services by `category` preserving query order; per group H2 = `services.category:` prefix + translated category label (`services.catConsultation|catRefraction|catDiagnostics|catTests|catGlasses|catOptical|catContactLenses|catSpecialist|catSurgery|catOther`; unknown key → raw category string). Cards: image (if any) 1200×800 crop, name (`am: nameAm||nameEn||name`, `en: nameEn||name`), description (`am: descriptionAm||descriptionEn`, `en: descriptionEn`), button `services.bookService` → `/book`. Empty: `services.empty` box.

### 7.4 Doctors `/doctors`

H1 `doctors.title`; intro = CMS `doctors_intro` (fallback `doctors.intro`). Empty: `doctors.empty` box. Cards: avatar 64 + name + specialty; `DoctorStatusBadge` (emerald dot + `doctors.availableNow` / amber `doctors.onLeave` / slate `doctors.nextAvailable {when}` via `formatNextAvailable` / `doctors.noSchedule`); today's shifts list `start–end`; buttons `bookAppointment` + `doctors.callToBook` (tel).

### 7.5 Book `/book`

H1 `book.title`, intro `book.intro`; card with client form (posts JSON to `/api/public/requests`):
fields `name`*, `phone`* (tel, hint `book.phoneHint`, placeholder `09xxxxxxxx`), `preferredDate`* (date, min = clinic today), `preferredTimeWindow` (select `any/morning/afternoon/evening` → labels `book.windowAny|windowMorning|windowAfternoon|windowEvening`, default any), `serviceInterest` (select of public service `name`s, first option `book.serviceNone` = value `''`), `message` optional textarea (500, hint `book.messageHint {max:500}`), hidden honeypot `website`, optional Turnstile widget, `consent` checkbox (required), submit `book.submit`/`book.submitting`, note `book.requiredNote`. 429 → `book.errorRateLimited`; validation → `book.errorValidation` + inline `book.errors.<code>` per known code (`required,tooLong,invalidDate,invalidTimeWindow,consent,pastSlot`; `invalidLanguage`/`spam` → generic validation message); other errors → `book.errorGeneric`. Success panel (`role=status`): `book.successTitle` + `book.successBody` (the front desk will call to confirm) + `book.successAgain` resets form. Field errors wired via `aria-invalid`/`aria-describedby`.

### 7.6 Contact `/contact`

H1 `contact.title`, intro `contact.intro`. Three cards: **Address** (`contact.address`; fallback `contact.pending`; link `contact.directions` or hardcoded `View on Google Maps` when URL matches `/maps\.app\.goo\.gl/i`, `target=_blank rel=noopener noreferrer`); **Phone** (`contact.phone`; primary as big `tel:` link, backup line `site.phoneBackupLabel {number}`, fallback `phonePending`; WhatsApp button (`contact.whatsapp`, `wa.me` from primary phone) + Telegram button (`contact.telegram`, `t.me` from primary phone) — both `_blank`); **Opening hours** (`contact.hours`, 7 lines Mon→Sun). Then feedback card: `feedback.title`, `feedback.intro`, client `FeedbackForm` (fields `name`*, optional `email` (hint `feedback.emailHint`), optional `rating` select ''|1..5 → `feedback.ratingNone|ratingPoor|ratingFair|ratingGood|ratingVeryGood|ratingExcellent`, `message`* textarea 1000, honeypot, Turnstile, consent `feedback.consent`, submit `feedback.submit`/`feedback.submitting`; errors/success mirror booking with `feedback.*` keys; success shows `feedback.successTitle/Body` + `feedback.successAgain`). Map block: H2 `contact.mapTitle`; when lat/lng set → **Google Maps `<iframe>`** (256 px, lazy, no API key) with the URL built by `MapEmbed::embedUrl(lat, lng, zoom)` + `Open in Maps` link (CMS `contact_map_url`, else a Google Maps search for the address).

### 7.7 FAQ `/faq`

H1 `faq.title`, intro `faq.intro`; items from CMS JSON → native `<details>/<summary>` accordion (plus-icon rotates, `group-open:rotate-45`); empty → `faq.empty` box; footer link `navContact →` `/contact`.

### 7.8 Privacy `/privacy`

H1 `privacy.title`; amber notice `privacy.updated`; body = CMS `privacy_body` split on blank lines into paragraphs.

### 7.9 404

`404` in big orange, `notFoundTitle`, `notFoundBody`, button `backHome` → `/`.

---

## 8. SEO / a11y

Titles/descriptions (i18n `site.meta.*`; canonical path; hreflang `?lang=en|am` + `x-default`):

| Page | title | description key | canonical |
|---|---|---|---|
| `/` | `Vision Eye Clinic — Eye care in Addis Ababa` (absolute) | `meta.homeDescription` | `/` |
| `/about` | `About us` | `meta.aboutDescription` | `/about` |
| `/services` | `Eye care services` | `meta.servicesDescription` | `/services` |
| `/doctors` | `Our doctors` | `meta.doctorsDescription` | `/doctors` |
| `/book` | `Book an appointment` | `meta.bookDescription` | `/book` |
| `/contact` | `Contact us` | `meta.contactDescription` | `/contact` |
| `/faq` | `Frequently asked questions` | `meta.faqDescription` | `/faq` |
| `/privacy` | `Privacy policy` | `meta.privacyDescription` | `/privacy` |

(Laravel: non-home titles render `<title>` = `"{title} · Vision Eye Clinic"` — Next template; home uses the absolute title.)

- **sitemap.xml**: exactly 8 URLs `/, /about, /services, /doctors, /book, /contact, /faq, /privacy`; each `lastModified = now`, `changeFrequency monthly`, `priority 1` for `/` else `0.7`, language alternates `en`/`am` absolute `?lang=` URLs.
- **robots.txt**: `User-agent: *`, `Allow: /`, `Disallow: /login, /api/, /settings`; `Sitemap: {base}/sitemap.xml`; `Host: {base}`.
- **OG image** (`/opengraph-image`): generated PNG 1200×630, `alt = 'Vision Eye Clinic — patient-first eye care in Addis Ababa'`, gradient `#9a3412→#c2410c`, eye emoji tile + `Vision Eye Clinic`, headline `Clear vision for every stage of life`, sub `Eye examinations · Glasses · Diagnostics · Specialist care`, footer `Addis Ababa, Ethiopia`. **Text is hardcoded English on purpose** (locale-invariant cacheability) even though CMS holds `og_title/og_subtitle/og_city` — keep or wire up, but note the divergence (§11).
- A11y: skip link; `aria-current` nav; form labels + `aria-invalid` + `aria-describedby` + `role=alert` errors + `role=status` success; honeypot `aria-hidden`; star ratings `role=img` + aria-label; decorative SVGs `aria-hidden`; accordion uses native `<details>`; header mobile toggle `aria-expanded/controls`; map container `aria-label`; social icons sr-only text.

---

## 9. i18n keys used (complete list)

Locales `en|am`, cookie `locale`, default `en`. Keys referenced by public pages/components (namespace `site.` unless noted):

- meta: `meta.homeTitle, meta.homeDescription, meta.aboutTitle, meta.aboutDescription, meta.servicesTitle, meta.servicesDescription, meta.doctorsTitle, meta.doctorsDescription, meta.bookTitle, meta.bookDescription, meta.contactTitle, meta.contactDescription, meta.faqTitle, meta.faqDescription, meta.privacyTitle, meta.privacyDescription`
- chrome: `skipToContent, navHome, navAbout, navServices, navDoctors, navContact, navFaq, bookAppointment, clinicNameAm, callNow, phonePending, phoneBackupLabel{number}, menuLabel, menuClose, languageLabel, english, amharic, announcementLabel, footerAbout, footerQuickLinks, footerContact, footerHours, footerPrivacy, footerRights{year,clinic}, footerMap, openInMaps, viewMap, zoomMap, notFoundTitle, notFoundBody, backHome`
- home: `home.servicesTitle, home.servicesIntro, home.viewAllServices, home.doctorsTitle, home.doctorsIntro, home.viewAllDoctors, home.testimonialsTitle, home.testimonialsIntro, home.testimonialsPlaceholder, home.locationTitle, home.hoursTitle, home.whyTitle, home.why1Title, home.why1Text, home.why2Title, home.why2Text, home.why3Title, home.why3Text, home.ctaTitle, home.ctaText, home.announcementsTitle, home.ratingAria{rating,max}`
- services: `services.title, services.intro, services.category, services.empty, services.bookService, services.details` (+ namespace `services.catConsultation, catRefraction, catDiagnostics, catTests, catGlasses, catOptical, catContactLenses, catSpecialist, catSurgery, catOther`)
- doctors: `doctors.title, doctors.intro, doctors.empty, doctors.availableNow, doctors.onLeave, doctors.nextAvailable{when}, doctors.noSchedule, doctors.callToBook, doctors.photoAlt{name}`
- book: `book.title, book.intro, book.name, book.namePlaceholder, book.phone, book.phonePlaceholder, book.phoneHint, book.preferredDate, book.timeWindow, book.windowAny, book.windowMorning, book.windowAfternoon, book.windowEvening, book.serviceInterest, book.serviceInterestPlaceholder, book.serviceNone, book.message, book.messageOptional, book.messagePlaceholder, book.messageHint{max}, book.language, book.languageEn, book.languageAm, book.consent, book.submit, book.submitting, book.requiredNote, book.successTitle, book.successBody{date,time,doctor}, book.successBodyGeneric, book.successAgain, book.errorGeneric, book.errorRateLimited, book.errorValidation, book.errors.required, book.errors.tooLong, book.errors.invalidDate, book.errors.invalidTimeWindow, book.errors.consent, book.errors.pastSlot`
- contact/feedback: `contact.title, contact.intro, contact.address, contact.phone, contact.hours, contact.map, contact.directions, contact.whatsapp, contact.telegram, contact.callTitle, contact.visitTitle, contact.messageTitle, contact.mapTitle, contact.pending, feedback.title, feedback.intro, feedback.name, feedback.namePlaceholder, feedback.email, feedback.emailHint, feedback.emailPlaceholder, feedback.optional, feedback.rating, feedback.ratingNone, feedback.ratingPoor, feedback.ratingFair, feedback.ratingGood, feedback.ratingVeryGood, feedback.ratingExcellent, feedback.message, feedback.messagePlaceholder, feedback.messageHint{max}, feedback.consent, feedback.submit, feedback.submitting, feedback.requiredNote, feedback.successTitle, feedback.successBody, feedback.successAgain, feedback.errorGeneric, feedback.errorRateLimited, feedback.errorValidation, feedback.errors.required, feedback.errors.tooLong, feedback.errors.invalidEmail, feedback.errors.invalidRating, feedback.errors.consent`
- faq/privacy: `faq.title, faq.intro, faq.empty, privacy.title, privacy.updated`
- namespace `settings` (hours labels): `settings.closed, settings.dayMon, dayTue, dayWed, dayThu, dayFri, daySat, daySun`
- namespace `common` (editor + forms): `common.save, saving, saved, unknownError`
- namespace `content` (CMS editor labels): `content.title, intro, viewSite, heroSection, homeSection, aboutSection, hoursSection, contactSection, announcementSection, faqSection, testimonialsSection, privacySection, footerSection, en, am, heroTitle, heroSubtitle, heroPromise, homeIntro, contactAddress, contactPhonePrimary, contactPhoneBackup, contactMapUrl, footerNote, announcementActive, announcementStart, announcementEnd, announcementText, faqQuestion, faqAnswer, addFaq, removeFaq, testimonialName, testimonialText, addTestimonial, removeTestimonial, privacyBody, servicesNotice, socialTiktok, socialInstagram, socialTelegram, socialsSection, announcementsSection, announcementsItems, addAnnouncement, removeAnnouncement, announcementTitle, announcementMessage, doctorsIntro, doctorsSection, city, country, specialty, ogTitle, ogSubtitle, ogCity, mapLat, mapLng, mapZoom, mapSection`

---

## 10. Test inventory (vitest → PHPUnit/feature tests)

`src/modules/public-site/content.test.ts`:
1. `pick` — am present → Amharic; am blank → English; locale en → English.
2. `parseItems` — `'not json'` → `[]`; array with invalid member filters to valid only; testimonials parse.
3. `resolveContent` — DB row `{hero_title, en:'DB title', am:''}` → `{en:'DB title', am:'DB title'}`; untouched keys equal `DEFAULT_CONTENT`.
4. `isAnnouncementVisible` (tz `Africa/Addis_Ababa`, now `2026-10-08T09:00:00Z`): default content (active 'true', empty text) → false; active+text → true; `start 2026-10-01` → true; `end 2026-10-01` → false; `start 2026-10-15` → false.

`src/modules/public-site/projection.test.ts`:
1. `toPublicService` with extra `currentPrice/price` columns → key set == `PUBLIC_SERVICE_FIELDS`, `findForbiddenFields` empty, JSON does not contain `'500'`.
2. `findForbiddenFields` on nested payload `{doctors:[{id,phone,nested:{salary}}],price}` → `Set{phone,salary,price}`.
3. Doctor built by `buildPublicDoctorStatus` (staff with phone/email attached) → keys == `PUBLIC_DOCTOR_FIELDS`, no forbidden fields.
4. `toPublicFeedback` with email/ipHash/showOnWebsite/createdBy → keys == `PUBLIC_FEEDBACK_FIELDS`, no forbidden, JSON lacks the email.
5. No name in `FORBIDDEN_PUBLIC_FIELDS` intersects the union of allow-lists.

`src/modules/public-site/format.test.ts`:
1. `telHref`: `'+251 911 223 344'`→`tel:+251911223344`; `'0911223344'`→`tel:0911223344`; `'123'`→null; null/''→null.
2. `whatsappHref`: digits/number/URL → `https://wa.me/251900000000` / as-is; ''→null.
3. `telegramHref`: `@handle`/handle/URL → `https://t.me/visioneye`; ''→null.
4. `workingHoursLines`: 7 lines in mon→sun order, `{day:'mon', value:'08:00–17:00'}` (en-dash), Sunday closed → `Closed`.

`src/modules/public-site/schemas.test.ts` (base valid booking: name `Abebe`, phone `0911223344`, date `2026-11-01`, window `morning`, language en, consent true, website ''):
1. Valid → `serviceInterest`/`message` normalized to null.
2. `consent:false` → throws.
3. date `01-11-2026` → throws; window `midnight` → throws.
4. message 501 chars throws; 500 chars passes (length 500).
5. honeypot: `website:''` not tripped; `'http://spam'` tripped.
6. Feedback: minimal → `email`/`rating` null, name kept; rating `'5'`→5, `4`→4, `''`→null; email `'  PATIENT@Example.COM '`→`'patient@example.com'`.
7. Feedback rejects rating `'6'`/`0`/`'great'`, bad email, blank message.
8. Feedback requires consent; message cap 1000 (1001 throws).
9. Feedback honeypot shared; schema itself throws on filled honeypot.

`src/modules/public-site/seo.test.ts` (env `PUBLIC_BASE_URL='https://vision.example.com/'`):
1. `siteBaseUrl` strips trailing slash; unset → `http://localhost:3000`.
2. `pageMetadata('/about')`: canonical `/about`; languages `{en:'/about?lang=en', am:'/about?lang=am', x-default:'/about'}`; `openGraph.url` = `https://vision.example.com/about`; twitter card `summary_large_image`; title plain.
3. Home `isHome:true` → `title === {absolute: …}`; `openGraph.url` = `https://vision.example.com`.

`src/modules/feedback/queries.test.ts` (`buildFeedbackWhere`):
1. Empty/blank filters → `{}`.
2. `q:'Abebe'` → `{AND:[{OR:[name contains insens., text…, email…]}]}`.
3. `website:'true'|'false'` → `{AND:[{showOnWebsite:…}]}`.
4. Combined q+website → two AND clauses, second `{showOnWebsite:true}`.
5. Unknown `website:'maybe'` → `{}`.

Additional feature tests to write for Laravel (behaviour pinned by this spec): POST `/api/public/requests` happy path (200 + `no-store` + an `AppointmentRequest` with status `NEW` + NO appointment + NO patient + audit `REQUEST_CREATED` with `ipHash` only), 429 after 6th call in an hour, 400 invalidJson, 422 field-error shapes incl. `phone:invalidPhone`, `preferredDate:pastSlot`, honeypot `website:'spam'`; the reception queue (`/requests`) — access (OWNER + RECEPTION only), the NEW counter + the sidebar badge, converting (SCHEDULED appointment + the patient matched or created + `REQUEST_CONVERTED` audit + the appointment messages), the status actions (`REQUEST_STATUS_CHANGED` audit), and the "already converted" guard; POST `/api/public/feedback` silent honeypot drop (`200 {ok:true,id:null}` before schema), 422 shapes, row hidden (`showOnWebsite=false`, `createdBy='public-website'`), audit `FEEDBACK_SUBMITTED` with ipHash only; GET doctors cache header + allow-list scan; photo/image routes 404 cases, publication-flag gates, content-type map, 1h cache header; saveSiteContent 403 for non-OWNER + audit diff; feedback toggle/delete OWNER-only with exact audit strings; robots/sitemap contents; announcement window at boundary dates.

---

## 11. Ambiguities resolved

1. **Booking "unhandled request"**: the legacy route auto-approved (`createPublicBooking` inserted a `CONFIRMED` appointment with a server-picked doctor) and the request queue was removed from the app. The follow-up brief restores the queue: the public booking now files an `AppointmentRequest` (status `NEW`) and the front desk converts it. The rules of `docs/spec/appointments.md` §6.11–6.13 (conversion, status tabs, request age) apply unchanged.
2. **Honeypot ordering differs between routes**: feedback drops silently (raw-body check first); booking's schema runs first so a filled honeypot returns `422 {website:'spam'}` and the silent-drop branch is unreachable. Preserved as-is (§3.4, §6.9) — flag to product if unification is wanted.
3. **`og_*` CMS keys vs hardcoded OG image**: keys are editable/stored but the generated OG image ignores them (deliberate cacheability comment). Rebuild keeping both: store keys, render hardcoded image (or wire keys in a later pass).
4. **WhatsApp/Telegram buttons derive from `contact_phone_primary`**, not from `social_telegram`; `social_*` URLs are footer icons only.
5. **Both maps are Google Maps embeds** built from the CMS position (`map_lat`, `map_lng`, `map_zoom`) by `App\Support\PublicSite\MapEmbed` — the keyless `output=embed` form, so no API key is needed. The "Open in Maps" link uses the CMS `contact_map_url`.
6. **Footer `hoursText` is always null** in the layout (`const hoursText = null`) — footer shows no hours today, though the prop exists.
7. **Announcement window is global**, read from the `en` columns of `announcement_active/start/end` regardless of locale; only `announcement_text` is localized.
8. `announcement_active` stores `am:''` — the `am` column of that key is ignored everywhere.
9. Home testimonials ordering: approved CMS feedback FIRST (newest first), then manual items, capped at 3 total; placeholder footnote only when zero approved feedback.
10. `services.details` i18n key exists but is unused by the public services page (card CTA is `services.bookService`).
