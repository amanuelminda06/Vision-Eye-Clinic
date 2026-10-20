# AGENTS.md

Working notes for anyone (human or agent) changing this repository.

## What this is

A single-tenant clinic ERP + bilingual public website, built with **Laravel 13
on PHP 8.3+** and **MySQL 8.0**. It was ported from a Next.js/Prisma/PostgreSQL
codebase; the porting contract lives in `docs/spec/` and the legacy code is
**gone**.

## Rules that are not negotiable

1. **Server-side authorization is the boundary.** Every route and every action
   checks the role. Hiding a button in the UI is a courtesy, never the
   control. The roles are `OWNER`, `RECEPTION`, `STAFF`
   (`App\Support\Roles`).
2. **No hard deletes.** Records are deactivated (`active = false`), never
   removed. The only exception is the feedback moderation, which deletes
   explicitly and audits it.
3. **Every write is audited.** `App\Support\Audit::write($entity, $entityId,
   $action, $old, $new, $user)` stores only the changed fields and redacts
   secrets. Read the old values **before** the write — reading them after the
   save returns the new value and silently produces an empty diff.
4. **Money is exact.** `NUMERIC(12,2)`, 2 dp, half-up, via `bcmath`. Never a
   float, never `round()`. A sale line snapshots the service name and price;
   later price changes never rewrite history.
5. **Instants are UTC.** The clinic timezone (a setting, default
   `Africa/Addis_Ababa`) decides what a "day" is. Day buckets are
   clinic-local; the stored value is always UTC.
6. **Phones are E.164** (`+2519…` / `+2517…`). `App\Support\Phone` is the
   only place that normalizes them.
7. **The public website never leaks.** No prices, no patient data, no internal
   ids. Public payloads are strict allow-list projections
   (`App\Support\PublicSite\Projection`) and the tests scan them recursively.
8. **The app never sends an SMS.** It builds an `sms:` link; the operator
   sends from the clinic phone and confirms in the app.
9. **No clinical records.** Notes are non-clinical free text.
10. **Every string is an i18n key**, never a sentence in the code.

## Conventions

- **ULID string primary keys** (`HasUlids`).
- **Money** — `App\Support\Money` (decimal helpers; `Money::money()`,
  `toDecimalString()`, `isMoneyInRange()`).
- **Time** — `App\Support\ClinicTime` (`clinicDateKey`, `clinicTimeKey`,
  `combineDateAndTime`, `startOfDay`, `endOfDay`, `dateKeyAdd`).
- **Pure logic lives in `app/Support/`** and is unit-tested without the
  database. The HTTP layer stays thin.
- **Tests** — `tests/Unit` for the pure logic, `tests/Feature` for the HTTP
  behaviour. Use PHPUnit 12 **attributes** (`#[DataProvider('x')]`) — the
  `@dataProvider` annotation form is silently ignored.
- **Style** — `./vendor/bin/pint` before committing.
- **i18n** — edit `messages/{en,am}.json`, then
  `php scripts/convert-messages.php && ./vendor/bin/pint lang`. Never edit
  `lang/` by hand (it is generated).

## Before you commit

```bash
php artisan test          # the whole suite must be green
./vendor/bin/pint --test   # no style drift
```

## The database

- Migrations are portable: they must run on **MySQL 8.0** (production) and on
  **SQLite** (the test suite).
- The production seed (`database/seeders/DatabaseSeeder.php`) creates the
  settings, one doctor, the **six real services** and the three accounts from
  the environment. It creates **no demo patients, sales or appointments**.
- Never run `migrate:fresh` on production.

## Deployment

Plesk + PHP 8.3+ + MySQL 8.0, **no Node.js on the host** (the compiled assets
are committed under `public/build/`). The full guide, the backup/restore
procedure and the go-live checklist are in
**[docs/PLESK_DEPLOYMENT.md](docs/PLESK_DEPLOYMENT.md)**.
