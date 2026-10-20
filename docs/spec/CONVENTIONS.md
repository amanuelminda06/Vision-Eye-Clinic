# Conventions — how the Laravel rebuild is organised

This document is the map for anyone (human or agent) extending the Vision Eye
Clinic ERP. Read it before adding a slice. It captures the file layout, the exact
behaviour of the shared support classes, the test patterns, and the handful of
non-obvious decisions made during the rebuild.

The authoritative behaviour for each area lives in `docs/spec/*.md`; this file is
about *how we write the code*, not *what the code must do*.

---

## 1. Stack & runtime

- **Laravel 13**, **PHP ^8.3** (built/tested on 8.4), **MySQL 8.0.32** in production.
- **No Node on the server.** Front-end assets are built locally with Vite and the
  compiled output (`public/build/`) is committed. There is no `npm` step at deploy.
- Shared Plesk hosting: the scheduler runs via `php artisan schedule:run` from cron
  (`docs/PLESK_DEPLOYMENT.md`).
- Tests use **SQLite `:memory:`** (`phpunit.xml`), so **all SQL must be portable**
  between SQLite and MySQL 8 — no MySQL-only functions, no `->raw()` relying on
  driver quirks. JSON columns are declared `json` on both.
- **UTC storage, clinic-timezone display.** Every `created_at`/`updated_at` is a
  UTC instant; anything user-facing goes through `ClinicTime` (display tz defaults
  to `Africa/Addis_Ababa` from `Setting`s).

## 2. Directory layout

```
app/
  Http/
    Controllers/           one controller per resource (thin — validation + call)
      Auth/                LoginController, ChangePasswordController, LogoutController
    Middleware/            SetLocale, SecurityHeaders, EnsureRole,
                           EnsurePasswordChanged, RedirectIfAuthenticated
  Models/                  Eloquent models (ULID string PKs, mirror Prisma schema)
  Support/                 pure domain helpers (no Eloquent side effects where possible)
    Audit, ClinicTime, LoginPolicy, MessageBadge, Money, Nav, Passwords, Phone, Roles
  helpers.php              global helpers: __t(), login_path(), clinic_now(), format_money()
bootstrap/app.php          middleware registration + aliases + guest redirect targets
config/                    auth.php (login_secret_path), hashing.php (argon2id), …
database/
  migrations/              Prisma-mirror schema, ported 1:1
  seeders/DatabaseSeeder   PRODUCTION seed (no demo data); DemoSeeder is dev-only
docs/spec/                 per-slice authoritative rebuild specs + this file
lang/{en,am}/*.php         GENERATED from messages/{en,am}.json (see §9)
messages/{en,am}.json      legacy next-intl catalogs (source of truth for strings)
resources/views/           Blade templates (layouts/, partials/, per-area dirs)
routes/web.php             all web routes; each slice registers its own group here
scripts/convert-messages.php   JSON → lang/*.php converter (run + Pint)
tests/Unit                  pure-function tests (fast, no DB)
tests/Feature               HTTP/DB tests (RefreshDatabase, :memory:)
```

## 3. Models

- **ULID string primary keys** everywhere (`use HasUlids;`), mirroring legacy `cuid()`.
  Never use auto-increment ints.
- Timestamps are `dateTimeTz` columns; `app.timezone` is `UTC`.
- **No hard deletes.** Deactivation is an `active = false` flag; records are never
  destroyed. `AuditLog` is append-only (`UPDATED_AT = null`).
- Money columns are `decimal(12,2)` and always handled as **strings** in PHP
  (never floats — see §6).
- Enums (`role`, status flags) are stored as short strings; the allowed set lives in
  `App\Support\Roles` / the relevant spec, validated at the boundary.

## 4. Routing & middleware

Routes live in `routes/web.php`. Each slice adds its own group. The middleware
aliases (registered in `bootstrap/app.php`):

| Alias | Class | Behaviour |
| --- | --- | --- |
| `auth` | Laravel's `Authenticate` | anon → redirect to `login_path()` (query cleared) |
| `guest` | `RedirectIfAuthenticated` | authed → `/dashboard` |
| `password.changed` | `EnsurePasswordChanged` | `must_change_password` → redirect to `/change-password` (except on `change-password`/`logout`) |
| `role:<ROLES>` | `EnsureRole` | GET wrong role → `/403`; mutation wrong role → hard 403; anon → login |
| (global) | `SecurityHeaders`, `SetLocale` | applied to every response |

**Ordering rule (legacy rule 17):** a protected route group is always
`['auth', 'password.changed', 'role:OWNER']` — password-change enforcement runs
**before** the role check, so a forced user is never shown a 403 for a page they
cannot yet use. Register role-gated routes like:

```php
Route::middleware(['auth', 'password.changed', 'role:OWNER'])->group(function () {
    Route::get('/revenue', [RevenueController::class, 'index'])->name('revenue');
});
```

- `/` is the **public site**, never the ERP. The only login page is
  `/login/<LOGIN_SECRET_PATH>`; any other `/login*` is a real 404. Always build
  login URLs with `login_path()` — never hard-code `/login`.
- `SetLocale` reads `?lang=en|am`, sets the `locale` cookie, and honours the cookie
  on later requests.

## 5. Controllers & validation

- Controllers are thin: validate/normalise input, call support classes or models,
  write audit, return a redirect or view.
- **Validation messages are i18n keys**, not UI text. E.g. return
  `->withErrors(['newPassword' => 'passwordTooShort'])`; the view renders
  `__('validation.'.$key)`. This keeps EN/AM parity automatic.
- The exact validation **order** matters and is documented per spec (e.g. change
  password: `required` → `passwordSame` → `passwordMismatch` → current-password
  match → strength). Preserve it.
- Mutation endpoints write an audit row via `Audit::write(...)` (§7).

## 6. Money, phone, time — exact APIs

These four helpers are load-bearing and have legacy ground-truth tests. Use them;
do not re-implement arithmetic inline.

### Money (`App\Support\Money`, `format_money()`)
Exact decimals only — inputs accepted as `int|float|string`, outputs are **strings**.
- `Money::money($v): string` — normalise to a 2-dp decimal string.
- `Money::addMoney(...$vs)`, `multiplyMoney($unit, $qty)`, `lineTotal($unit, $qty)`,
  `sumLineTotals(array)`, `averageMoney($total, $count)`.
- `Money::percentChange($current, $previous): ?float` (null when previous is 0).
- `format_money($value, $currency = 'ETB')` → `"ETB 1,234.50"` (pass the settings
  currency as the 2nd arg).
- Constants: `MAX_MONEY = '9999999999.99'`, `ZERO_MONEY = '0.00'`.

### Phone (`App\Support\Phone`)
- `Phone::toE164(?string): ?string` — accepts `09…`/`07…`/`9…`/`7…`/`+2519…`,
  strips separators, returns `+2519…`/`+2517…` or null when invalid.
- `Phone::isValid(?string): bool`, `Phone::parse(?string): array`,
  `Phone::display(?string $e164): ?string`, `Phone::phoneSearchVariants(?string): ?array`.
- Store E.164 only. Empty input is null, never `''`.

### Time (`App\Support\ClinicTime`)
Ported **verbatim** from legacy `datetime.ts`. The API:
- `ClinicTime::now(): DateTimeImmutable` — current instant in the clinic tz.
- `ClinicTime::nowUtc()`, `ClinicTime::utcNowSql(): string`.
- `tzOffsetMs($date, $tz)`, `clinicDateKey($date)`, `clinicTimeKey($date)`,
  `combineDateAndTime($dateKey, $timeKey)` (throws `Invalid date/time: {dk} {tk}`).
- `dateKeyAdd($dateKey, $days)`, `startOfDay`/`endOfDay`, `formatInTz($date, $tz, $options?)`,
  `formatDateRange($start, $end, $tz)`.

`formatInTz` is a hand-rolled Intl substitute (PHP has no `intl` here). It replicates
ICU/en-GB output for the option combinations the app actually uses; it is covered by
60 fixtures captured from Node v20 (`tests/Unit/ClinicTimeTest.php`). Do not change
its option handling without re-verifying against Node.

**Slice-boundary notes:** `dateKeyAddMonths` and `dateKeysBetween` are introduced by
the HR/appointments work in later slices — check the relevant spec before assuming
they exist.

## 7. Audit (`App\Support\Audit`)

Every write calls `Audit::write($entity, $entityId, $action, $before, $after, $user, $ip)`.

- Store **only changed fields**; pass the whole before/after objects — the diff is
  computed for you (`Audit::diffObjects`).
- Secret-looking keys (`password`, `passwordHash`, `token`, `secret`, `otp`, …) are
  redacted to `[REDACTED]` automatically, on both halves.
- `$before`/`$after` are stored as **arrays**; the `AuditLog` model's `array` cast
  encodes once. Do not pre-`json_encode` (that double-encodes).
- **Never throws.** A failed audit write is logged and swallowed so it cannot roll
  back the business operation. `Log::shouldReceive('error')` is asserted in tests.
- `VIEW` rows are deduped: one per `(user, entity, entityId)` per minute. Anonymous
  events (`$user = null`) are always written.
- `Audit::clientIp($request)` (x-forwarded-for first hop → x-real-ip → null) and
  `Audit::hashIp(?string): string` (sha-256 hex, `''` for null/empty) support
  public-endpoint abuse correlation without storing raw IPs.

## 8. Roles & navigation

- `App\Support\Roles` is canonical: `OWNER`, `RECEPTION`, `STAFF`;
  `Roles::ALL_ROLES` (alias `ALL`), `isRole()`, `hasRole()`,
  `canAccessRoute()`, `canSeeSection()`, `isOwner()`, `canManage()`, `canDoReception()`.
- **Authorization is always server-side.** The role middleware is the gate; hiding a
  nav link is cosmetic only.
- `App\Support\Nav::visibleFor($role)` returns the sidebar modules for a role
  (ported from legacy `NAV_MODULES`), with `Nav::isActive($href)` for highlighting.
  Note: `nav.feedback` (`/dashboard/feedback`) is **OWNER-only**.

## 9. i18n (English + Amharic)

- Source of truth: `messages/{en,am}.json` (legacy next-intl catalogs).
- Generated: `lang/{en,am}/*.php` via `php scripts/convert-messages.php`, then
  `./vendor/bin/pint lang`. **Never edit `lang/` by hand** — edit the JSON and
  re-run both commands. `LangParityTest` enforces JSON↔PHP fidelity and key-set
  parity (EN and AM may differ in key *order*, never in key *set*).
- Placeholders keep the next-intl `{name}` style: use **`__t('key', ['name' => $x])`**
  for any message with placeholders, and plain `__('key')` otherwise.
- Errors/audit strings are keys; translate at the edge.

## 10. Testing

- **PHPUnit 12: metadata via attributes only.** `@dataProvider` annotations are
  silently ignored — use `#[DataProvider('providerMethod')]` on a `public static`
  provider.
- `tests/Unit` — pure functions, no DB (e.g. `MoneyTest`, `ClinicTimeTest`,
  `RolesTest`, `AuditDiffTest`, `LangParityTest`, `NavTest`).
- `tests/Feature` — HTTP + DB (`use RefreshDatabase;`, SQLite `:memory:`;
  `use Tests\Concerns\CreatesUsers;` for accounts).
- **Never weaken an assertion to make a test pass.** If legacy disagrees with the
  implementation, fix the implementation or verify which is the gold standard.
- Feature-test caveats:
  - The app instance and auth guard persist across requests in a single test —
    re-`actingAs($user->fresh())` after mutating the user row.
  - CSRF is skipped in tests; `clientIp()` is null → the rate-limit key is `unknown`.
  - `$_SERVER`/`$_ENV` override `env()` (restore them afterwards); `putenv` alone
    does **not**.
  - PHPUnit's `Exception` extends `RuntimeException` — don't wrap `fail()` in a
    `catch (\RuntimeException)`.
  - Environment-dependent branches (e.g. production-only HSTS) are tested by
    overriding `config(['app.env' => 'production'])` and calling the middleware
    directly.
- Run everything: `php artisan test`. Format: `./vendor/bin/pint`.

## 11. Seeding

- `DatabaseSeeder` is the **production** seed: 17 settings, the doctor staff row,
  the three env accounts. Idempotent (settings only create missing keys; accounts
  upsert by email). **No demo patients/sales/appointments, ever.**
- Later slices **extend** `DatabaseSeeder` (tags/services in 2/3, message library in
  6, site content in 7, …) and must keep it demo-free.
- Dev/demo data belongs in a separate `DemoSeeder`, never in the production path.
- `SeederTest` asserts keys/values/counts and that the invalid-env path fails fast
  without echoing values.

## 12. Non-obvious decisions (read before "fixing" these)

- **Locale cookie is excluded from encryption** (`$middleware->encryptCookies(['locale'])`
  in `bootstrap/app.php`). `SetLocale` is a global middleware that runs *after* the
  web group's encrypt phase, so without this exclusion the cookie is written
  plaintext and read back as null.
- **Audit stores array diffs**, not pre-encoded JSON (see §7) to avoid double encoding.
- **`login_path()`** hides the secret; `/login` itself is a hard 404 by design.
- **`formatInTz`** intentionally reproduces specific ICU quirks — comments in the
  class explain each one. Ground truth is Node/ICU, not intuition.
- Password hashing is **argon2id** (`HASH_DRIVER=argon2id`); tests use a cheap work
  factor via `ARGON_*` env vars (same algorithm, small cost).
